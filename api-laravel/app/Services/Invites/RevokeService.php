<?php

declare(strict_types=1);

namespace App\Services\Invites;

use App\Models\Invite;
use App\Enums\InviteStatus;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Invites::RevokeService
 * (app/services/invites/revoke_service.rb).
 */
class RevokeService extends BaseService
{
    public function __construct(
        private readonly ?Invite $invite,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invite');

        if ($this->invite === null || $this->invite->status !== InviteStatus::Pending) {
            return $result->notFoundFailure('invite');
        }

        $this->invite->markAsRevoked();

        $result->invite = $this->invite;

        return $result;
    }
}
