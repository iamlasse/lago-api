<?php

declare(strict_types=1);

namespace App\Services\FixedCharges;

use App\Models\Plan;
use App\Models\AddOn;
use App\Services\BaseResult;
use App\Services\BaseService;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use App\Services\ChargeModels\FilterPropertiesService;
use App\Services\ChargeModels\BuildDefaultPropertiesService;

/**
 * Port of Rails' FixedCharges::CreateService
 * (app/services/fixed_charges/create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): FixedCharges::EmitEventsService (fixed_charge_events +
 *   billing jobs) — the emission point is marked below.
 * - TODO(port): FixedCharges::CreateChildrenJob cascade dispatch.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Plan $plan,
        private readonly array $params,
        private readonly int $timestamp = 0,
        private readonly bool $cascadeUpdates = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fixed_charge');

        if ($this->plan === null) {
            return $result->notFoundFailure('plan');
        }

        $plan = $this->plan;
        $params = $this->params;

        try {
            $fixedCharge = DB::transaction(function () use ($plan, $params) {
                $addOn = $this->addOn($plan, $params);

                $fixedCharge = $plan->fixedCharges()->make([
                    'organization_id' => $plan->organization_id,
                    'add_on_id' => $addOn->id,
                    'code' => $params['code'] ?? null,
                    'invoice_display_name' => $params['invoice_display_name'] ?? null,
                    'charge_model' => $params['charge_model'] ?? null,
                    'parent_id' => $params['parent_id'] ?? null,
                    'pay_in_advance' => $params['pay_in_advance'] ?? false,
                    'prorated' => $params['prorated'] ?? false,
                    'units' => $params['units'] ?? 0,
                ]);

                $properties = (isset($params['properties']) && is_array($params['properties']) && $params['properties'] !== [])
                    ? $params['properties']
                    : BuildDefaultPropertiesService::call(
                        $params['charge_model'] ?? null,
                    )->properties;

                $fixedCharge->properties = FilterPropertiesService::call(
                    chargeable: $fixedCharge,
                    properties: $properties,
                )->properties;

                $this->saveFixedCharge($fixedCharge);

                if (($params['tax_codes'] ?? null) !== null && $params['tax_codes'] !== []) {
                    ApplyTaxesService::call(
                        fixedCharge: $fixedCharge,
                        taxCodes: (array) $params['tax_codes'],
                    )->raiseIfError();
                }

                // TODO(port): FixedCharges::EmitEventsService — the
                // fixed_charge_events emission point (billing is a later
                // milestone).

                return $fixedCharge;
            });

            $result->fixed_charge = $fixedCharge;

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return $result->singleValidationFailure('value_already_exist', 'code');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // Rails: rescue ActiveRecord::RecordNotFound (add_on find).
            return $result->notFoundFailure('add_on');
        }
    }

    /**
     * Rails: add_on — `find` by id (404s), `find_by!` by code, or
     * ArgumentError when neither is provided (raised uncaught, like Rails).
     *
     * @param  array<string, mixed>  $params
     */
    private function addOn(Plan $plan, array $params): AddOn
    {
        if (($params['add_on_id'] ?? null) !== null && $params['add_on_id'] !== '') {
            // Rails' uuid attribute type casts non-uuid strings to nil before
            // the query (find on "invalid_id" raises RecordNotFound); Postgres
            // would raise here, so a non-uuid id goes straight to the
            // ModelNotFound -> add_on not-found failure, like Rails.
            $addOnId = $params['add_on_id'];

            if (! is_string($addOnId)
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $addOnId) !== 1) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }

            return AddOn::query()
                ->where('organization_id', $plan->organization_id)
                ->findOrFail($addOnId);
        }

        if (($params['add_on_code'] ?? null) !== null && $params['add_on_code'] !== '') {
            return AddOn::query()
                ->where('organization_id', $plan->organization_id)
                ->where('code', $params['add_on_code'])
                ->firstOrFail();
        }

        throw new InvalidArgumentException('Either add_on_id or add_on_code must be provided');
    }

    private function saveFixedCharge(\App\Models\FixedCharge $fixedCharge): void
    {
        $errors = $fixedCharge->validateAttributes();

        if ($errors !== []) {
            static::makeResult()->recordValidationFailure($errors)->raiseIfError();
        }

        $fixedCharge->save();
    }
}
