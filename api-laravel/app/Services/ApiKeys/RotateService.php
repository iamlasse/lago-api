<?php

declare(strict_types=1);

namespace App\Services\ApiKeys;

use App\Models\ApiKey;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' ApiKeys::RotateService
 * (app/services/api_keys/rotate_service.rb): creates a replacement key and
 * expires the provided one (immediately, or at the premium `expires_at`).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): ApiKeyMailer.rotated — no mailer infra.
 * - TODO(port): Utils::SecurityLog.produce — ClickHouse security logs.
 */
class RotateService extends BaseService
{
    /**
     * @param  array<string, mixed>  $params  name / expires_at
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

        $expiresAt = $this->params['expires_at'] ?? null;

        // Rails: params[:expires_at].present? && !License.premium? →
        // forbidden_failure!(code: "cannot_rotate_with_provided_date")
        if ($expiresAt !== null && ! $this->premium()) {
            return $result->forbiddenFailure('cannot_rotate_with_provided_date');
        }

        $expiresAt = $expiresAt ?? now();
        $newApiKey = $apiKey->organization->apiKeys()->make(['name' => $this->params['name'] ?? null]);

        DB::transaction(function () use ($newApiKey, $apiKey, $expiresAt): void {
            $newApiKey->save();
            $apiKey->expires_at = $expiresAt;
            $apiKey->save();
        });

        CacheService::expireCache($apiKey->value);

        $result->api_key = $newApiKey;

        return $result;
    }
}
