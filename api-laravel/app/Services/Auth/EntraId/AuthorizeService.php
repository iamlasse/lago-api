<?php

declare(strict_types=1);

namespace App\Services\Auth\EntraId;

use Illuminate\Support\Str;
use App\Services\BaseResult;
use Illuminate\Support\Facades\Cache;

/**
 * Port of Rails' Auth::EntraId::AuthorizeService (app/services/auth/entra_id/
 * authorize_service.rb) — builds the Entra ID authorization URL and seeds the
 * state → email mapping the login/accept-invite services consume.
 */
class AuthorizeService extends BaseService
{
    private ?string $state = null;

    public function __construct(
        private readonly string $email,
        private readonly ?string $inviteToken = null,
    ) {
        parent::__construct();

        // Rails: initialize_state — SecureRandom.uuid → email, 90 seconds.
        Cache::put($this->state(), $this->email, self::stateTtl());
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('entra_id_integration', 'invite', 'url');

        try {
            if ($this->inviteToken !== null && $this->inviteToken !== '') {
                $this->checkInvite($this->inviteToken, $this->email, $result);
            }

            $this->checkEntraIdIntegration($this->email, $result);

            /** @var \App\Models\Integrations\EntraIdIntegration $integration */
            $integration = $result->entra_id_integration;

            $params = [
                'client_id' => $integration->clientId(),
                'response_type' => 'code',
                'response_mode' => 'query',
                'scope' => 'openid profile email',
                'redirect_uri' => self::redirectUri(),
                'state' => $this->state(),
            ];

            // Rails: URI::HTTPS.build(host:, path:, query: params.to_query) —
            // Hash#to_query sorts the keys; ksort keeps the URL byte-identical.
            ksort($params);

            $result->url = 'https://'.$integration->host()
                .'/'.$integration->tenantId()
                .'/oauth2/v2.0/authorize?'.http_build_query($params);

            return $result;
        } catch (ValidationError $e) {
            return $result->singleValidationFailure($e->getMessage());
        }
    }

    private function state(): string
    {
        // Cached so execute() and the constructor see the same value.
        return $this->state ??= Str::uuid()->toString();
    }
}
