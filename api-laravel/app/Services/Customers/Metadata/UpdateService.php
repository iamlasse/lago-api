<?php

declare(strict_types=1);

namespace App\Services\Customers\Metadata;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\CustomerMetadata;

use function is_array;

/**
 * Port of Rails' Customers::Metadata::UpdateService — upserts the metadata
 * payload on a customer and removes metadata no longer present in the
 * payload (sanitize_metadata).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly Customer $customer,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customer');
        $customer = $this->customer;

        $createdMetadataIds = [];

        foreach ($this->params as $payloadMetadata) {
            $payloadMetadata = is_array($payloadMetadata) ? $payloadMetadata : [];

            $metadata = null;

            if (isset($payloadMetadata['id'])) {
                $metadata = $customer->metadata()->where('id', $payloadMetadata['id'])->first();
            }

            $payloadMetadata['display_in_invoice'] = $payloadMetadata['display_in_invoice'] ?? false;

            if ($metadata !== null) {
                $metadata->fill([
                    'key' => $payloadMetadata['key'] ?? $metadata->key,
                    'value' => $payloadMetadata['value'] ?? $metadata->value,
                    'display_in_invoice' => $payloadMetadata['display_in_invoice'],
                ]);

                $errors = $metadata->validateAttributes();

                if ($errors !== []) {
                    return $result->recordValidationFailure($errors);
                }

                $metadata->save();

                continue;
            }

            $createdMetadata = $this->createMetadata($customer, $payloadMetadata, $result);

            if ($createdMetadata === null) {
                return $result;
            }

            $createdMetadataIds[] = $createdMetadata->id;
        }

        // Rails: delete metadata that are no more linked to the customer.
        $this->sanitizeMetadata($customer, $this->params, $createdMetadataIds);

        $result->customer = $customer;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function createMetadata(Customer $customer, array $payload, BaseResult $result): ?CustomerMetadata
    {
        $metadata = $customer->metadata()->make([
            'key' => $payload['key'] ?? null,
            'value' => $payload['value'] ?? null,
            'display_in_invoice' => $payload['display_in_invoice'] ?? false,
            'organization_id' => $customer->organization_id,
        ]);

        $errors = $metadata->validateAttributes();

        if ($errors !== []) {
            $result->recordValidationFailure($errors);

            return null;
        }

        $metadata->save();

        return $metadata;
    }

    /**
     * Rails: `sanitize_metadata` — delete customer metadata whose ids are
     * neither in the payload nor just created.
     *
     * @param  array<string, mixed>  $argsMetadata
     * @param  list<string>  $createdMetadataIds
     */
    protected function sanitizeMetadata(Customer $customer, array $argsMetadata, array $createdMetadataIds): void
    {
        $updatedMetadataIds = [];

        foreach ($argsMetadata as $metadata) {
            if (is_array($metadata) && isset($metadata['id'])) {
                $updatedMetadataIds[] = $metadata['id'];
            }
        }

        $allIds = $customer->metadata()->pluck('id')->all();
        $notNeededIds = array_diff($allIds, $updatedMetadataIds, $createdMetadataIds);

        if ($notNeededIds !== []) {
            $customer->metadata()->whereIn('id', $notNeededIds)->delete();
        }
    }
}
