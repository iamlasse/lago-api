<?php

declare(strict_types=1);

namespace App\Services\UsageAttributionTypes;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\UsageAttributionType;

/**
 * Port of Rails' UsageAttributionTypes::UpdateService — updates a type's
 * attributes. When the type has attributed values (including discarded
 * ones), only name / description / attribution_keys stay editable; a flat
 * type cannot take children; the parent must exist among the
 * organization's types.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?UsageAttributionType $usageAttributionType,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('usage_attribution_type');

        if ($this->usageAttributionType === null) {
            return $result->notFoundFailure('usage_attribution_type');
        }

        /** @var UsageAttributionType $type */
        $type = $this->usageAttributionType;

        if (array_key_exists('name', $this->params)) {
            $type->name = $this->params['name'];
        }

        if (array_key_exists('description', $this->params)) {
            $type->description = $this->params['description'];
        }

        if (array_key_exists('code', $this->params)) {
            $type->code = UsageAttributionTypeService::normalizeCode($this->params['code']);
        }

        if (array_key_exists('attribution_keys', $this->params)) {
            $type->attribution_keys = UsageAttributionTypeService::normalizeAttributionKeys($this->params['attribution_keys']);
        }

        if (array_key_exists('role', $this->params)) {
            $type->role = UsageAttributionTypeService::roleIndex($this->params['role']);
        }

        if (array_key_exists('parent_id', $this->params)) {
            $type->parent_id = ($this->params['parent_id'] ?? null) !== null ? $this->params['parent_id'] : null;
        }

        // Rails: frozen_changes — the editable subset excludes name,
        // description and attribution_keys once values are attributed.
        $attributed = $type->usageAttributionValues()->withTrashed()->exists();

        if ($attributed) {
            $frozen = array_values(array_diff(
                array_keys($type->getDirty()),
                ['name', 'description', 'attribution_keys'],
            ));

            if ($frozen !== []) {
                $errors = [];

                foreach ($frozen as $field) {
                    $errors[$field] = ['usage_already_attributed'];
                }

                return $result->validationFailure($errors);
            }
        }

        if (array_key_exists('parent_id', $this->params) && $type->parent_id !== null
            && ! $type->organization->usageAttributionTypes()->where('id', $type->parent_id)->exists()) {
            return $result->notFoundFailure('parent_usage_attribution_type');
        }

        if ($type->flat() && $type->children()->exists()) {
            return $result->singleValidationFailure('cannot_be_flat_with_children', 'role');
        }

        $errors = UsageAttributionTypeService::validate($type);

        if ($errors !== null) {
            return $result->recordValidationFailure($errors);
        }

        $type->save();

        $result->usage_attribution_type = $type;

        return $result;
    }
}
