<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Contract;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Contracts::CreateService
 * (app/services/contracts/create_service.rb) — records a new agreement.
 * The plan is optional by design: a plan-less contract prices through
 * directly attached rate cards. The contract is active when its start has
 * arrived, pending when it starts in the future.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('contract');
        $params = $this->params;

        try {
            $customer = $this->organization->customers()
                ->where('external_id', $params['external_customer_id'] ?? null)
                ->first();

            if ($customer === null) {
                return $result->notFoundFailure('customer');
            }

            $catalogPlan = null;
            if (($params['plan_code'] ?? null) !== null) {
                $catalogPlan = $this->organization->catalogPlans()
                    ->where('code', $params['plan_code'])
                    ->first();

                if ($catalogPlan === null) {
                    return $result->notFoundFailure('plan');
                }
            }

            // Date columns: a malformed value would silently cast to null
            // instead of failing, so formats are rejected explicitly.
            foreach (['billing_anchor_date', 'started_at', 'ended_at'] as $field) {
                if (($params[$field] ?? null) !== null && ($params[$field] ?? null) !== ''
                    && ! Datetime::validFormat($params[$field], 'any')) {
                    return $result->singleValidationFailure('value_is_invalid', $field);
                }
            }

            // A window that already closed cannot be created: nothing would
            // ever terminate it, leaving a zombie active contract.
            $timezone = $customer->applicableTimezone();
            $endedAt = isset($params['ended_at']) && $params['ended_at'] !== '' && $params['ended_at'] !== null
                ? CarbonImmutable::parse((string) $params['ended_at'], 'UTC')
                : null;

            if ($endedAt !== null && $endedAt->setTimezone($timezone)->lessThanOrEqualTo(now())) {
                return $result->singleValidationFailure('already_ended', 'ended_at');
            }

            // One live agreement per external id. The partial unique index
            // closes the concurrency race.
            if (Contract::query()
                ->where('organization_id', $this->organization->id)
                ->whereIn('status', Contract::LIVE_STATUSES)
                ->where('external_id', $params['external_id'] ?? null)
                ->exists()) {
                return $result->singleValidationFailure('value_already_exists', 'external_id');
            }

            $startedAt = isset($params['started_at']) && $params['started_at'] !== '' && $params['started_at'] !== null
                ? CarbonImmutable::parse((string) $params['started_at'], 'UTC')
                : CarbonImmutable::now('UTC');

            // TODO(port): settings resolution (billing entity / payment
            // method) — GraphQL-only inputs so far.

            $contract = DB::transaction(function () use ($result, $customer, $catalogPlan, $startedAt, $endedAt): Contract {
                $contract = new Contract([
                    'organization_id' => $this->organization->id,
                    'customer_id' => $customer->id,
                    'catalog_plan_id' => $catalogPlan?->id,
                    'external_id' => $this->params['external_id'] ?? null,
                    'name' => $this->params['name'] ?? null,
                    'billing_time' => ($this->params['billing_time'] ?? null) ?: 'calendar',
                    'billing_anchor_date' => $this->params['billing_anchor_date'] ?? null,
                    'started_at' => $startedAt,
                    'ended_at' => $endedAt,
                    'status' => $startedAt->isFuture() ? 'pending' : 'active',
                ]);

                $errors = $contract->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $contract->save();

                if ($contract->catalogPlan !== null) {
                    MaterializeRateCardsService::callBang(contract: $contract);
                }

                // Port of Rails' BillingSegments::ScheduleJob
                // .perform_after_commit(customer.id) — a card billed in
                // advance is due the moment the contract starts, so its
                // invoice must not wait for the hourly producer.
                DB::afterCommit(fn () => dispatch(new \App\Jobs\BillingSegments\ScheduleJob($customer->id)));

                return $contract;
            });

            $result->contract = $contract;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
