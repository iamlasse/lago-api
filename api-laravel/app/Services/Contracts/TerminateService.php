<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Contracts::TerminateService
 * (app/services/contracts/terminate_service.rb) — ends a live contract's
 * lifecycle. An active contract that already ran is terminated; a pending
 * one that never started is canceled instead — two names for the same "no
 * longer live" outcome. Both transitions are terminal.
 *
 * Lifecycle state only. This does not itself stop billing: the schedule
 * bounds on contract.ended_at, not on status.
 */
class TerminateService extends BaseService
{
    public function __construct(
        private readonly ?Contract $contract,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('contract');
        $contract = $this->contract;

        try {
            if ($contract === null) {
                return $result->notFoundFailure('contract');
            }

            $status = (string) $contract->getRawOriginal('status');

            if (! in_array($status, ['pending', 'active'], true)) {
                return $result->singleValidationFailure('cannot_terminate', 'contract');
            }

            if ($status === 'active') {
                $contract->status = 'terminated';
                $contract->terminated_at = now();
            } else {
                $contract->status = 'canceled';
                $contract->canceled_at = now();
            }

            $errors = $contract->validateAttributes();
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $contract->save();

            $result->contract = $contract;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
