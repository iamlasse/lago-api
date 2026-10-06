<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\FixedChargeUnitsOverrides;

use App\Models\FixedCharge;
use App\Models\Subscription;
use App\Models\SubscriptionFixedChargeUnitsOverride;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Subscriptions::FixedChargeUnitsOverrides::WriteService
 * (app/services/subscriptions/fixed_charge_units_overrides/write_service.rb)
 * — writes (or updates) the subscription's units override row for a parent
 * fixed charge.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): FixedCharges::EmitEventsService — no fixed charge events are
 *   emitted, so the pay-in-advance invoice job never fires (it only runs
 *   when emitted events exist).
 */
class WriteService extends BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly FixedCharge $fixedCharge,
        private readonly mixed $units,
        private readonly bool $applyUnitsImmediately = false,
        private readonly ?int $timestamp = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('units_override');

        try {
            DB::transaction(function () use ($result): void {
                $unitsOverride = SubscriptionFixedChargeUnitsOverride::query()
                    ->withTrashed()
                    ->where('subscription_id', $this->subscription->id)
                    ->where('fixed_charge_id', $this->fixedCharge->id)
                    ->first()
                    ?? new SubscriptionFixedChargeUnitsOverride();

                $unitsOverride->subscription_id = $this->subscription->id;
                $unitsOverride->fixed_charge_id = $this->fixedCharge->id;
                $unitsOverride->organization_id = $this->subscription->organization_id;
                $unitsOverride->units = $this->units;

                if ($unitsOverride->trashed()) {
                    $unitsOverride->restore();
                }

                $errors = method_exists($unitsOverride, 'validateAttributes')
                    ? $unitsOverride->validateAttributes()
                    : [];

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $unitsOverride->save();

                // TODO(port): FixedCharges::EmitEventsService.call! + the
                // Invoices::CreatePayInAdvanceFixedChargesJob dispatch gated
                // on the emitted events.

                $result->units_override = $unitsOverride;
            });
        } catch (FailedResult $e) {
            return $e->result;
        }

        return $result;
    }
}
