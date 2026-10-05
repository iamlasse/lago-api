<?php

declare(strict_types=1);

namespace App\Services\Auth\Okta;

use Illuminate\Support\Str;
use App\Services\BaseResult;
use Illuminate\Support\Facades\Cache;

/**
 * Port of Rails' Auth::Okta::AuthorizeService (app/services/auth/okta/
 * authorize_service.rb) — builds the Okta authorization URL and seeds the
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
        $result = static::makeResult('okta_integration', 'invite', 'url');

        try {
            if ($this->inviteToken !== null && $this->inviteToken !== '') {
                $this->checkInvite($this->inviteToken, $this->email, $result);
            }

            $this->checkOktaIntegration($this->email, $result);

            /** @var \App\Models\Integrations\OktaIntegration $oktaIntegration */
            $oktaIntegration = $result->okta_integration;

            $params = [
                'client_id' => $oktaIntegration->clientId(),
                'response_type' => 'code',
                'response_mode' => 'query',
                'scope' => 'openid profile email',
                'redirect_uri' => self::redirectUri(),
                'state' => $this->state(),
            ];

            // Rails: URI::HTTPS.build(host:, path:, query: params.to_query) —
            // Hash#to_query sorts the keys; PHP's http_build_query preserves
            // order, so ksort keeps the URL byte-identical.
            ksort($params);

            $result->url = 'https://'.$oktaIntegration->host().'/oauth2/v1/authorize?'.http_build_query($params);

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
