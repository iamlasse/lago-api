<?php

declare(strict_types=1);

namespace App\Services\Auth\EntraId;

use App\Services\BaseResult;
use App\Services\Invites\AcceptService;
use App\Support\Organizations\AuthenticationMethods;

/**
 * Port of Rails' Auth::EntraId::AcceptInviteService (app/services/auth/
 * entra_id/accept_invite_service.rb) — the SSO variant of invite acceptance.
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
            'entra_id_integration',
            'invite',
            'entra_id_access_token',
            'userinfo',
        );

        try {
            $this->checkState($this->state, $result);
            $this->checkCode($this->code);
            $this->checkEntraIdIntegration($result->email, $result);
            $this->checkInvite($this->inviteToken, $result->email, $result);
            $this->queryEntraIdAccessToken($result);
            $this->checkUserinfo($result->email, $result);

            return AcceptService::call(
                invite: $result->invite,
                password: static::generatedPassword(),
                loginMethod: AuthenticationMethods::ENTRA_ID,
            );
        } catch (ValidationError $e) {
            return $result->singleValidationFailure($e->getMessage());
        } catch (\App\Http\Client\LagoHttpError $e) {
            return $result->singleValidationFailure('entra_id_request_error');
        }
    }
}
