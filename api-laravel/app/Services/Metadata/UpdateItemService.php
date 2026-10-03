<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use stdClass;
use App\Models\Wallet;
use App\Models\ItemMetadata;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Metadata::UpdateItemService
 * (app/services/metadata/update_item_service.rb) — updates the metadata of
 * the record with new content, creates it if absent, or deletes it.
 *
 * Behaviour matrix (old value | new value | partial | action):
 *   nil    | non-nil | any   | set new value
 *   non-nil| nil     | false | delete metadata item
 *   non-nil| nil     | true  | no-op
 *   non-nil| non-nil | false | replace with new value
 *   non-nil| non-nil | true  | merge new value
 */
class UpdateItemService extends BaseService
{
    public function __construct(
        private readonly Wallet $owner,
        /** @var array<string, mixed>|null */
        private readonly mixed $value,
        private readonly bool $partial = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('metadata', 'metadataChanged');
        $metadata = $this->owner->metadata()->first();

        $changed = false;

        if ($this->createMetadata($metadata)) {
            $metadata = new ItemMetadata([
                'organization_id' => $this->owner->organization_id,
                'owner_type' => $this->owner->railsName(),
                'owner_id' => $this->owner->id,
                'value' => $this->asJsonObject($this->value),
            ]);

            $errors = $metadata->validateAttributes();

            if ($errors !== []) {
                return $result->recordValidationFailure($errors);
            }

            $metadata->save();

            $changed = true;
        } elseif ($this->replaceMetadata($metadata)) {
            $metadata->value = $this->asJsonObject($this->value);
            $metadata->save();

            $changed = $metadata->wasChanged();
        } elseif ($this->mergeMetadata($metadata)) {
            $merged = array_merge((array) $metadata->value, (array) $this->value);
            $metadata->value = $this->asJsonObject($merged);
            $metadata->save();

            $changed = $metadata->wasChanged();
        } elseif ($this->deleteMetadata($metadata)) {
            $metadata->delete();

            $changed = true;
        }

        $result->metadata = $metadata;
        $result->metadataChanged = $changed;

        return $result;
    }

    /**
     * The column carries a CHECK (jsonb_typeof(value) = 'object'); an empty
     * PHP array must be encoded as a JSON object, not the array literal [].
     */
    private function asJsonObject(mixed $value): mixed
    {
        return is_array($value) && $value === [] ? new stdClass() : $value;
    }

    private function createMetadata(?ItemMetadata $metadata): bool
    {
        return $metadata === null
            && $this->value !== null
            && ($this->value !== [] || ! $this->partial);
    }

    private function replaceMetadata(?ItemMetadata $metadata): bool
    {
        return $metadata !== null && ! $this->partial && $this->value !== null;
    }

    private function mergeMetadata(?ItemMetadata $metadata): bool
    {
        return $metadata !== null && $this->partial && $this->value !== [] && $this->value !== null;
    }

    private function deleteMetadata(?ItemMetadata $metadata): bool
    {
        return $metadata !== null && ! $this->partial && $this->value === null;
    }
}
