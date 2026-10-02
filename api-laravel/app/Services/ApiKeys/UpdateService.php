<?php

declare(strict_types=1);

namespace App\Services\ApiKeys;

use App\Models\ApiKey;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' ApiKeys::UpdateService
 * (app/services/api_keys/update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::SecurityLog.produce — ClickHouse security logs.
 */
class UpdateService extends BaseService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly ?ApiKey $apiKey,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('api_key');

        $apiKey = $this->apiKey;

        if ($apiKey === null) {
            return $result->notFoundFailure('api_key');
        }

        // Rails: params[:permissions].present? && !api_key.organization.api_permissions_enabled?
        $permissions = $this->params['permissions'] ?? null;
        $permissionsPresent = $permissions !== null && $permissions !== [];

        if ($permissionsPresent && ! $this->apiPermissionsEnabled($apiKey)) {
            return $result->forbiddenFailure('premium_integration_missing');
        }

        $updates = [
            'name' => array_key_exists('name', $this->params) ? $this->params['name'] : $apiKey->name,
            'permissions' => $permissionsPresent ? $permissions : $apiKey->permissions,
        ];

        $validationErrors = CreateService::validatePermissions($updates['permissions']);

        if ($validationErrors !== []) {
            return $result->recordValidationFailure($validationErrors);
        }

        $apiKey->name = $updates['name'];
        $apiKey->permissions = $updates['permissions'];
        $apiKey->save();

        CacheService::expireCache($apiKey->value);

        $result->api_key = $apiKey;

        return $result;
    }

    /**
     * Rails: `Organization#api_permissions_enabled?` — License.premium? &&
     * premium_integrations.include?('api_permissions').
     */
    private function apiPermissionsEnabled(ApiKey $apiKey): bool
    {
        return $this->premium()
            && in_array('api_permissions', (array) ($apiKey->organization->premium_integrations ?? []), true);
    }
}
