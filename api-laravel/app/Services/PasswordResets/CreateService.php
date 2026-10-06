<?php

declare(strict_types=1);

namespace App\Services\PasswordResets;

use App\Models\User;
use App\Services\BaseResult;
use App\Models\PasswordReset;
use App\Services\BaseService;

/**
 * Port of Rails' PasswordResets::CreateService
 * (app/services/password_resets/create_service.rb) — a 30-minute token.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): PasswordResetMailer.with(password_reset:).requested
 *   .deliver_later — no mailer infra.
 * - TODO(port): Utils::SecurityLog.produce("user.password_reset_requested").
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?User $user,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('id');

        if ($this->user === null) {
            return $result->notFoundFailure('user');
        }

        $passwordReset = new PasswordReset([
            'user_id' => $this->user->id,
            'token' => bin2hex(random_bytes(20)),
            'expire_at' => now()->addMinutes(30),
        ]);

        $passwordReset->save();

        $result->id = $passwordReset->id;

        return $result;
    }
}
