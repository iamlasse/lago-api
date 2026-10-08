<?php

declare(strict_types=1);

namespace App\Services\BillingSegments;

use App\Models\Customer;
use App\Models\PricingUnit;
use Carbon\CarbonImmutable;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillingSegment;
use App\Models\ContractRateCard;
use Illuminate\Support\Facades\DB;
use App\Services\Customers\LockService;
use Illuminate\Database\QueryException;
use App\Services\Billing\RateCards\BuildScheduleService;
use App\Services\ContractRateCards\AdvanceBillingClockService as CardAdvanceBillingClockService;

/**
 * Port of Rails' BillingSegments::ScheduleService (app/services/
 * billing_segments/schedule_service.rb) — produces a customer's due billing
 * segments. It resolves what a run needs once — the card's schedule, its
 * pricing unit — and hands them down, so no collaborator looks anything up.
 */
class ScheduleService extends BaseService
{
    /** Rails: OVERLAP_CONSTRAINT. */
    public const string OVERLAP_CONSTRAINT = 'billing_segments_no_overlapping_periods';

    public function __construct(
        private readonly Customer $customer,
        private readonly ?CarbonImmutable $timestamp = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('billing_segments');
        $timestamp = $this->timestamp ?? CarbonImmutable::now();
        $scheduled = [];

        try {
            DB::transaction(function () use (&$scheduled, $timestamp, $result): void {
                LockService::call(
                    customer: $this->customer,
                    scope: 'billing_schedule',
                    body: function () use (&$scheduled, $timestamp, $result): void {
                        $this->dueCards()->each(function (ContractRateCard $card) use (&$scheduled, $timestamp, $result): void {
                            $scheduled = [...$scheduled, ...$this->scheduleCard($card, $timestamp, $result)];
                        });
                    },
                );
            });
        } catch (\App\Services\Failures\FailedResult $error) {
            // Rails: rescue BaseService::FailedResult → fail_with_error!.
            $result->failWithError($error);

            return $result;
        } catch (QueryException $error) {
            if ($this->overlappingPeriods($error)) {
                $result->singleValidationFailure(errorCode: 'overlapping_periods', field: 'billing_segment');

                return $result;
            }

            throw $error;
        }

        $result->billing_segments = $scheduled;

        return $result;
    }

    /** Rails: `#due_cards` — the customer's cards whose clock has come due. */
    private function dueCards()
    {
        return ContractRateCard::query()
            ->dueForBilling($this->timestamp ?? CarbonImmutable::now())
            ->where('contract_rate_cards.organization_id', $this->customer->organization_id)
            ->where('contracts.customer_id', $this->customer->id)
            ->with([
                'rateCard',
                'ratePhases.rateOverride',
                'contract.customer',
            ])
            ->get();
    }

    /**
     * Rails: `#schedule_card` — build the calendar's answer, subtract what is
     * stored, write the rest, move the clock.
     *
     * @return list<BillingSegment>
     */
    private function scheduleCard(ContractRateCard $card, CarbonImmutable $timestamp, BaseResult $result): array
    {
        $schedule = BuildScheduleService::callBang(contractRateCard: $card)->schedule;
        $pricingUnit = $this->pricingUnitOf($card, $result);

        $missing = MissingBillableSegmentsService::callBang(
            contractRateCard: $card,
            schedule: $schedule,
            timestamp: $timestamp,
        )->billable_segments;

        $written = CreateService::callBang(
            contractRateCard: $card,
            billableSegments: $missing,
            pricingUnit: $pricingUnit,
        )->billing_segments;

        CardAdvanceBillingClockService::callBang(
            contractRateCard: $card,
            schedule: $schedule,
            timestamp: $timestamp,
        );

        return $written;
    }

    /** Rails: `#pricing_unit_of` — the unit the card's applied code resolves to. */
    private function pricingUnitOf(ContractRateCard $card, BaseResult $result): ?PricingUnit
    {
        $code = $card->rateCard->applied_pricing_unit_code;

        if ($code === null || $code === '') {
            return null;
        }

        $unit = $this->pricingUnitsByCode()[$code] ?? null;

        if ($unit === null) {
            $result->notFoundFailure(resource: 'pricing_unit')->raiseIfError();
        }

        return $unit;
    }

    /** @return array<string, PricingUnit> */
    private function pricingUnitsByCode(): array
    {
        return PricingUnit::query()
            ->where('organization_id', $this->customer->organization_id)
            ->get()
            ->keyBy('code')
            ->all();
    }

    /** Rails: `#overlapping_periods?` — the overlap EXCLUDE constraint fired. */
    private function overlappingPeriods(QueryException $error): bool
    {
        $message = $error->getMessage();
        $previous = $error->getPrevious();

        return str_contains($message, self::OVERLAP_CONSTRAINT)
            || ($previous !== null && str_contains($previous->getMessage(), self::OVERLAP_CONSTRAINT));
    }
}
