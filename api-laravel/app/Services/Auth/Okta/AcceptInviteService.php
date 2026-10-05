<?php

declare(strict_types=1);

namespace App\Services\Auth\Okta;

use App\Services\BaseResult;
use App\Services\Invites\AcceptService;
use App\Support\Organizations\AuthenticationMethods;

/**
 * Port of Rails' Auth::Okta::AcceptInviteService (app/services/auth/okta/
 * accept_invite_service.rb) — the SSO variant of invite acceptance.
 */
class AcceptInviteService extends BaseService
{
    public function __construct(
        private readonly string $inviteToken,
        string $code,
        private readonly string $state,
    ) {
        parent::__construct();

        $this->code = $code;
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult(
            'email',
            'okta_integration',
            'invite',
            'okta_access_token',
            'userinfo',
        );

        try {
            $this->checkState($this->state, $result);
            $this->checkCode($this->code);
            $this->checkOktaIntegration($result->email, $result);
            $this->checkInvite($this->inviteToken, $result->email, $result);
            $this->queryOktaAccessToken($result);
            $this->checkUserinfo($result->email, $result);

            return AcceptService::call(
                invite: $result->invite,
                password: static::generatedPassword(),
                loginMethod: AuthenticationMethods::OKTA,
            );
        } catch (ValidationError $e) {
            return $result->singleValidationFailure($e->getMessage());
        } catch (\App\Http\Client\LagoHttpError $e) {
            return $result->singleValidationFailure('okta_request_error');
        }
    }
}
