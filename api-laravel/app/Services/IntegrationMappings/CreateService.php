<?php

declare(strict_types=1);

namespace App\Services\IntegrationMappings;

use App\Models\Integration;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' IntegrationMappings::CreateService (app/services/
 * integration_mappings/create_service.rb).
 */
class CreateService extends BaseService
{
    /**
     * @param  array<string, mixed>  $args
     * @param  object|null  $user  Rails passes the membership user; unused in
     *                             the port (the audit trail is TODO(port)).
     */
    public function __construct(
        private readonly array $args,
        private readonly ?object $user = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_mapping');
        $args = $this->args;

        $integration = Integration::query()->find(self::uuidOrNull($args['integration_id'] ?? null));

        if ($integration === null) {
            return $result->notFoundFailure('integration');
        }

        $billingEntityId = $args['billing_entity_id'] ?? null;

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

        /** @var \App\Models\IntegrationMappings\BaseMapping $integrationMapping */
        $integrationMapping = new $mappingClass([
            'organization_id' => $integration->organization_id,
            'integration_id' => $args['integration_id'],
            'mappable_id' => $args['mappable_id'],
            'mappable_type' => $args['mappable_type'],
            'billing_entity_id' => $billingEntityId,
        ]);

        if (array_key_exists('external_id', $args)) {
            $integrationMapping->external_id = $args['external_id'];
        }
        if (array_key_exists('external_account_code', $args)) {
            $integrationMapping->external_account_code = $args['external_account_code'];
        }
        if (array_key_exists('external_name', $args)) {
            $integrationMapping->external_name = $args['external_name'];
        }

        $errors = $integrationMapping->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $integrationMapping->save();

        $result->integration_mapping = $integrationMapping;

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
}
