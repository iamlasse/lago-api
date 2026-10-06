<?php

declare(strict_types=1);

namespace App\Services\IntegrationCollectionMappings;

use App\Models\Integration;
use App\Services\BaseResult;
use App\Services\BaseService;

use function array_key_exists;

/**
 * Port of Rails' IntegrationCollectionMappings::CreateService (app/services/
 * integration_collection_mappings/create_service.rb).
 */
class CreateService extends BaseService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_collection_mapping');
        $params = $this->params;

        $integration = Integration::query()->find(self::uuidOrNull($params['integration_id'] ?? null));

        if ($integration === null) {
            return $result->notFoundFailure('integration');
        }

        $billingEntityId = $params['billing_entity_id'] ?? null;

        if ($billingEntityId !== null) {
            // Rails: integration.organization.billing_entities.find_by(id:) —
            // an ill-formed uuid answers not-found like Rails (the uuid cast
            // yields no record).
            $billingEntity = self::uuidOrNull($billingEntityId) === null
                ? null
                : $integration->organization->billingEntities()
                    ->where('id', $billingEntityId)
                    ->first();

            if ($billingEntity === null) {
                return $result->notFoundFailure('billing_entity');
            }
        }

        $mappingClass = Factory::new_instance(integration: $integration);

        /** @var \App\Models\IntegrationCollectionMappings\BaseCollectionMapping $mapping */
        $mapping = new $mappingClass([
            'integration_id' => $params['integration_id'],
            'mapping_type' => $this->mappingTypePosition($params['mapping_type'] ?? null),
            'billing_entity_id' => $billingEntityId,
        ]);

        // Rails: integration_collection_mapping.organization = integration.organization.
        $mapping->organization_id = $integration->organization_id;

        if (array_key_exists('external_id', $params)) {
            $mapping->external_id = $params['external_id'];
        }
        if (array_key_exists('external_account_code', $params)) {
            $mapping->external_account_code = $params['external_account_code'];
        }
        if (array_key_exists('external_name', $params)) {
            $mapping->external_name = $params['external_name'];
        }
        if (array_key_exists('tax_nexus', $params)) {
            $mapping->tax_nexus = $params['tax_nexus'];
        }
        if (array_key_exists('tax_code', $params)) {
            $mapping->tax_code = $params['tax_code'];
        }
        if (array_key_exists('tax_type', $params)) {
            $mapping->tax_type = $params['tax_type'];
        }
        if (array_key_exists('currencies', $params)) {
            $mapping->currencies = $params['currencies'];
        }

        $errors = $mapping->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $mapping->save();

        $result->integration_collection_mapping = $mapping;

        return $result;
    }

    /**
     * Rails: the uuid find_by casts an ill-formed id to no record; Postgres
     * would reject the literal, so the port guards it.
     */
    private static function uuidOrNull(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^\{?[0-9a-f]{8}\b-[0-9a-f]{4}\b-[0-9a-f]{4}\b-[0-9a-f]{4}\b-[0-9a-f]{12}$/i', $id) === 1
            ? $id
            : null;
    }

    /**
     * Rails: the enum assignment accepts the wire name ("fallback_item") or
     * an integer position; the port stores the integer position.
     */
    private function mappingTypePosition(mixed $mappingType): ?int
    {
        if (is_int($mappingType)) {
            return $mappingType;
        }

        if (is_string($mappingType)) {
            return \App\Models\IntegrationCollectionMappings\BaseCollectionMapping::mappingTypes()[$mappingType] ?? null;
        }

        return null;
    }
}
