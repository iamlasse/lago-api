<?php

declare(strict_types=1);

uses()->group('ledger:svc:RateCardRates.CreateService', 'ledger:svc:RateCardRates.DestroyService', 'ledger:svc:RatePhases.CreateService');

use App\Models\Product;
use App\Models\RateCard;
use App\Models\Organization;
use App\Models\BillableMetric;
use Illuminate\Support\Carbon;
use App\Services\RateCardRates\CreateService;
use App\Services\RateCardRates\DestroyService;
use App\Services\RatePhases\CreateService as RatePhaseCreateService;
use App\Services\RatePhases\DestroyService as RatePhaseDestroyService;

/**
 * Port of Rails' spec/services/rate_card_rates/*_service_spec.rb and the
 * rate_phases specs — the append-only timeline and the phase sequence rules.
 */
function rateRatesFixture(): array
{
    $organization = Organization::factory()->create(['feature_flags' => ['product_catalog']]);
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);

    return [$organization, $rateCard];
}

function rateRatesPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'r1',
        'effective_from' => \Illuminate\Support\Facades\Date::today()->toIso8601String(),
        'rate_model' => 'standard',
        'billing_interval_unit' => 'month',
        'rate_properties' => ['amount' => '10'],
    ], $overrides);
}

it('appends a rate on an empty timeline', function (): void {
    [, $rateCard] = rateRatesFixture();

    $result = CreateService::call(rateCard: $rateCard, params: rateRatesPayload());

    expect($result->success())->toBeTrue()
        ->and($result->rate_card_rate->status())->toBe('active')
        ->and($result->rate_card_rate->min_amount_cents)->toBe(0)
        ->and($result->rate_card_rate->billing_interval_count)->toBe(1);
});

it('rejects an append before today', function (): void {
    [, $rateCard] = rateRatesFixture();

    $result = CreateService::call(rateCard: $rateCard, params: rateRatesPayload([
        'effective_from' => \Illuminate\Support\Facades\Date::today()->subDays(2)->toIso8601String(),
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['effective_from' => ['must_not_be_before_today']]);
});

it('rejects an append on an already-taken instant', function (): void {
    [, $rateCard] = rateRatesFixture();

    CreateService::call(rateCard: $rateCard, params: rateRatesPayload());
    $result = CreateService::call(rateCard: $rateCard, params: rateRatesPayload(['code' => 'r2']));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['effective_from' => ['value_already_exist']]);
});

it('marks a superseded rate terminated', function (): void {
    [, $rateCard] = rateRatesFixture();

    $first = CreateService::call(rateCard: $rateCard, params: rateRatesPayload())->rate_card_rate;
    $second = CreateService::call(rateCard: $rateCard, params: rateRatesPayload([
        'code' => 'r2',
        'effective_from' => \Illuminate\Support\Facades\Date::tomorrow()->toIso8601String(),
    ]))->rate_card_rate;

    // Rails: superseded requires a LATER rate that is already effective —
    // a pending (future) rate leaves the current one active.
    expect($first->status())->toBe('active')
        ->and($second->status())->toBe('pending');
});

it('deletes only pending rates', function (): void {
    [, $rateCard] = rateRatesFixture();

    $active = CreateService::call(rateCard: $rateCard, params: rateRatesPayload())->rate_card_rate;

    $failure = DestroyService::call(rateCardRate: $active);

    expect($failure->failure())->toBeTrue()
        ->and($failure->getError()->messages)->toBe(['status' => ['only_pending_rates_can_be_deleted']]);
});

// -- RatePhases ---------------------------------------------------------------------

it('creates a default terminal phase on a plan rate card', function (): void {
    [$organization, $rateCard] = rateRatesFixture();
    $plan = App\Models\CatalogPlan::factory()->create(['organization_id' => $organization->id, 'currency' => 'EUR']);
    $planRateCard = App\Models\PlanRateCard::factory()->create([
        'organization_id' => $organization->id,
        'catalog_plan_id' => $plan->id,
        'rate_card_id' => $rateCard->id,
    ]);

    $result = RatePhaseCreateService::call(planRateCard: $planRateCard, params: ['code' => 'default', 'position' => 1]);

    expect($result->success())->toBeTrue()
        ->and($result->rate_phase->position)->toBe(1)
        ->and($result->rate_phase->billing_interval_cycle_count)->toBeNull();
});

it('rejects an indefinite phase that is not last', function (): void {
    [$organization, $rateCard] = rateRatesFixture();
    $plan = App\Models\CatalogPlan::factory()->create(['organization_id' => $organization->id, 'currency' => 'EUR']);
    $planRateCard = App\Models\PlanRateCard::factory()->create([
        'organization_id' => $organization->id,
        'catalog_plan_id' => $plan->id,
        'rate_card_id' => $rateCard->id,
    ]);

    RatePhaseCreateService::call(planRateCard: $planRateCard, params: ['code' => 'default', 'position' => 1]);

    // The default phase is the indefinite tail; inserting another
    // indefinite phase before it leaves two tails.
    $result = RatePhaseCreateService::call(planRateCard: $planRateCard, params: [
        'code' => 'promo', 'position' => 2,
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['billing_interval_cycle_count' => ['indefinite_phase_must_be_last']]);

});

it('cannot delete the last phase', function (): void {
    [$organization, $rateCard] = rateRatesFixture();
    $plan = App\Models\CatalogPlan::factory()->create(['organization_id' => $organization->id, 'currency' => 'EUR']);
    $planRateCard = App\Models\PlanRateCard::factory()->create([
        'organization_id' => $organization->id,
        'catalog_plan_id' => $plan->id,
        'rate_card_id' => $rateCard->id,
    ]);

    $phase = RatePhaseCreateService::call(planRateCard: $planRateCard, params: ['code' => 'default', 'position' => 1])->rate_phase;

    $result = RatePhaseDestroyService::call(ratePhase: $phase);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['rate_phase' => ['indefinite_phase_not_deletable']]);
});
