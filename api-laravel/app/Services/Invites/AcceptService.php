<?php

declare(strict_types=1);

namespace App\Services\Invites;

use Throwable;
use App\Models\Invite;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\AuthToken;
use Illuminate\Support\Facades\DB;
use App\Services\Users\RegisterFromInviteService;
use App\Support\Organizations\AuthenticationMethods;

/**
 * Port of Rails' Invites::AcceptService (app/services/invites/
 * accept_service.rb) — shared by the email/password invite acceptance and the
 * SSO variants (google/okta/entra_id).
 *
 * Rails-side side effects intentionally deferred to their own ledger rows:
 * UserDevices::RegisterService (devices/jobs are not part of this slice).
 */
class AcceptService extends BaseService
{
    public function __construct(
        private readonly ?Invite $invite = null,
        private readonly ?string $token = null,
        private readonly string $password = '',
        private readonly string $loginMethod = AuthenticationMethods::EMAIL_PASSWORD,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('membership', 'token', 'user');

        // Rails: invite = args[:invite] || Invite.find_by(token:, status: :pending).
        $invite = $this->invite
            ?? Invite::query()->pending()->where('token', $this->token)->first();

        if ($invite === null) {
            return $result->notFoundFailure('invite');
        }

        if (! in_array(
            $this->loginMethod,
            (array) ($invite->organization->authentication_methods ?? []),
            true,
        )) {
            return $result->singleValidationFailure('login_method_not_authorized', $this->loginMethod);
        }

        // ActiveRecord::Base.transaction — Rails reassigns `result` from
        // UsersService inside the block and keeps going even when the inner
        // result carries a failure (a quirk worth keeping: the invite is
        // still marked as accepted).
        DB::transaction(function () use ($result, $invite): void {
            $inner = RegisterFromInviteService::call(invite: $invite, password: $this->password);

            try {
                $inner->raiseIfError();
            } catch (\App\Services\Failures\FailedResult $e) {
                $result->failWithError($e);
            }

            $result->user = $inner->user;
            $result->membership = $inner->membership;

            try {
                $inner->token = AuthToken::encode($inner->user, extra: ['login_method' => $this->loginMethod]);
            } catch (Throwable $e) {
                $result->serviceFailure('token_encoding_error', $e->getMessage(), $e);
            }

            $result->token = $inner->token;

            // invite.recipient = result.membership; invite.mark_as_accepted!
            $invite->membership_id = $result->membership?->id;
            $invite->save();
            $invite->markAsAccepted();
        });

        return $result;
    }
}
