<?php

declare(strict_types=1);

namespace App\Services\ApiKeys;

use App\Models\ApiKey;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' ApiKeys::DestroyService
 * (app/services/api_keys/destroy_service.rb): soft-deletes by expiring the
 * key now — and refuses to expire the last non-expiring key of the
 * organization unless forced.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): ApiKeyMailer.destroyed — no mailer infra.
 * - TODO(port): Utils::SecurityLog.produce — ClickHouse security logs.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?ApiKey $apiKey,
        private readonly bool $force = false,
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

        // Rails: unless force || organization.api_keys.non_expiring.without(api_key).exists?
        $hasOtherNonExpiring = $apiKey->organization->apiKeys()
            ->whereNull('expires_at')
            ->where('id', '!=', $apiKey->id)
            ->exists();

        if (! $this->force && ! $hasOtherNonExpiring) {
            return $result->singleValidationFailure('last_non_expiring_api_key');
        }

        // Rails: api_key.touch(:expires_at)
        $apiKey->expires_at = now();
        $apiKey->save();

        CacheService::expireCache($apiKey->value);

        $result->api_key = $apiKey;

        return $result;
    }
}
