<?php

declare(strict_types=1);

namespace App\Services\ApiKeys;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' ApiKeys::CreateService
 * (app/services/api_keys/create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): ApiKeyMailer.created — no mailer infra.
 * - TODO(port): Utils::SecurityLog.produce — ClickHouse security logs.
 */
class CreateService extends BaseService
{
    /**
     * Rails signature: `call(params)` with `organization` inside params.
     *
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly array $params,
        private readonly ?Organization $organization = null,
    ) {
        parent::__construct();
    }

    /**
     * Port of the ApiKey model's permission validations
     * (`permissions_keys_compliance` / `permissions_values_allowed`) — the
     * messages mirror Rails' fallback texts for the custom error symbols.
     *
     * @return array<string, list<string>>
     */
    public static function validatePermissions(mixed $permissions): array
    {
        if ($permissions === null || $permissions === []) {
            return ['permissions' => ["can't be blank"]];
        }

        $forbiddenKeys = array_diff(array_keys((array) $permissions), ApiKey::RESOURCES);

        $values = [];
        foreach ((array) $permissions as $modes) {
            foreach ((array) $modes as $mode) {
                $values[] = $mode;
            }
        }

        if ($forbiddenKeys !== [] || array_diff($values, ApiKey::MODES) !== []) {
            return ['permissions' => ['is invalid']];
        }

        return [];
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('api_key');

        // Rails: return result.forbidden_failure! unless License.premium?
        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        // Rails: params[:permissions].present? && !organization.api_permissions_enabled?
        // (api_permissions is a premium integration; without the premium
        // license the organization never has it).
        $permissions = $this->params['permissions'] ?? null;
        $organization = $this->organization ?? $this->params['organization'] ?? null;

        if ($this->permissionsPresent($permissions) && ! $this->apiPermissionsEnabled($organization)) {
            return $result->forbiddenFailure('premium_integration_missing');
        }

        $apiKey = new ApiKey([
            'organization_id' => $organization?->id,
            'name' => $this->params['name'] ?? null,
            // Rails: `attribute :permissions, default: -> { default_permissions }`.
            'permissions' => $this->permissionsPresent($permissions) ? $permissions : ApiKey::defaultPermissions(),
        ]);

        // Rails: save! rescued into record_validation_failure! — the model's
        // validations (permissions keys/values) are ported here because the
        // frozen ApiKey model carries no validation hooks.
        $validationErrors = self::validatePermissions($apiKey->permissions);

        if ($validationErrors !== []) {
            return $result->recordValidationFailure($validationErrors);
        }

        $apiKey->save();
        $result->api_key = $apiKey;

        return $result;
    }

    private function permissionsPresent(mixed $permissions): bool
    {
        return $permissions !== null && $permissions !== [];
    }

    /**
     * Rails: `Organization#api_permissions_enabled?` — generated for every
     * premium integration as `License.premium? && premium_integrations
     * .include?(name)`.
     */
    private function apiPermissionsEnabled(?object $organization): bool
    {
        return $this->premium()
            && $organization !== null
            && in_array('api_permissions', (array) ($organization->premium_integrations ?? []), true);
    }
}
