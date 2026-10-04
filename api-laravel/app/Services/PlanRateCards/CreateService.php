<?php

declare(strict_types=1);

namespace App\Services\PlanRateCards;

use App\Models\CatalogPlan;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' PlanRateCards::CreateService
 * (app/services/plan_rate_cards/create_service.rb).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?CatalogPlan $catalogPlan,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('plan_rate_card');
        $catalogPlan = $this->catalogPlan;
        $params = $this->params;

        try {
            if ($catalogPlan === null) {
                return $result->notFoundFailure('plan');
            }

            // A plan with contracts is immutable: pricing changes go through
            // a new plan and a contract migration.
            if ($catalogPlan->attachedToContracts()) {
                return $result->singleValidationFailure('plan_locked', 'plan');
            }

            $rateCard = $catalogPlan->organization->rateCards()
                ->where('code', $params['rate_card_code'] ?? null)
                ->first();

            if ($rateCard === null) {
                return $result->notFoundFailure('rate_card');
            }

            // Fees bill in the card currency and the invoice in the plan
            // currency; a mismatch must fail at configuration time.
            if ($rateCard->currency !== $catalogPlan->currency) {
                return $result->singleValidationFailure('currency_does_not_match', 'currency');
            }

            return DB::transaction(function () use ($result, $catalogPlan, $rateCard, $params): BaseResult {
                // Rails: catalog_plan.with_lock.
                CatalogPlan::query()->whereKey($catalogPlan->id)->lockForUpdate()->first();

                // One card per pricing slice: a plan may hold several cards
                // of the same item only when they cover different filter
                // slices (default + EU, ...).
                $slicePriced = $catalogPlan->appliedRateCards()
                    ->join('rate_cards', 'rate_cards.id', '=', 'plan_rate_cards.rate_card_id')
                    ->where('rate_cards.product_id', $rateCard->product_id)
                    ->where('rate_cards.product_filter_id', $rateCard->product_filter_id)
                    ->whereNull('plan_rate_cards.deleted_at')
                    ->exists();

                if ($slicePriced) {
                    $errorCode = $rateCard->product_filter_id !== null
                        ? 'product_filter_already_priced'
                        : 'product_already_priced';

                    return $result->singleValidationFailure($errorCode, 'rate_card');
                }

                $planRateCard = $catalogPlan->appliedRateCards()->create([
                    'organization_id' => $catalogPlan->organization_id,
                    'rate_card_id' => $rateCard->id,
                    'units' => $params['units'] ?? null,
                ]);

                $errors = $planRateCard->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                // Phases can be authored atomically with the entry: a
                // provided sequence goes through the same validations as the
                // single-phase ops (an explicit empty list is rejected
                // there) and a failure rolls the whole create back. Omitted
                // or null, the entry starts on a single default terminal
                // phase.
                if (array_key_exists('rate_phases', $params) && $params['rate_phases'] !== null) {
                    \App\Services\RatePhases\ReplaceService::callBang(
                        planRateCard: $planRateCard,
                        phasesParams: $params['rate_phases'],
                    );
                } else {
                    \App\Services\RatePhases\CreateService::callBang(
                        planRateCard: $planRateCard,
                        params: ['code' => 'default', 'position' => 1],
                    );
                }

                $result->plan_rate_card = $planRateCard;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
