<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v2/rate_cards',
    'ledger:rest:GET:/api/v2/rate_cards',
    'ledger:rest:GET:/api/v2/rate_cards/:code',
    'ledger:rest:PUT:/api/v2/rate_cards/:code',
    'ledger:rest:PATCH:/api/v2/rate_cards/:code',
    'ledger:rest:DELETE:/api/v2/rate_cards/:code',
    'ledger:rest:GET:/api/v2/rate_cards/:code/rates',
    'ledger:rest:POST:/api/v2/rate_cards/:code/rates',
);

use App\Models\Product;
use App\Models\RateCard;
use App\Models\Organization;
use App\Models\RateCardRate;
use App\Models\BillableMetric;
use Illuminate\Support\Carbon;

/**
 * Port of Rails' spec/requests/api/v2/rate_cards_controller_spec.rb (and
 * the nested rates spec).
 */
function rateCardsTestOrganization(): array
{
    $organization = Organization::factory()->create(['feature_flags' => ['product_catalog']]);

    return [$organization, $organization->apiKeys()->first()];
}

function rateCardsTestProduct(Organization $organization): Product
{
    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    return Product::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);
}

it('creates a rate card with a nested rate', function (): void {
    [$organization, $apiKey] = rateCardsTestOrganization();
    $product = rateCardsTestProduct($organization);

    $this->postJson('/api/v2/rate_cards', ['rate_card' => [
        'product_code' => $product->code,
        'name' => 'API card',
        'code' => 'api_card',
        'currency' => 'EUR',
        'rates' => [[
            'code' => 'base',
            'effective_from' => \Illuminate\Support\Facades\Date::today()->toIso8601String(),
            'rate_model' => 'standard',
            'billing_interval_unit' => 'month',
            'rate_properties' => ['amount' => '10'],
        ]],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($product): void {
            $json->where('rate_card.code', 'api_card')
                ->where('rate_card.currency', 'EUR')
                ->where('rate_card.product_code', $product->code)
                ->where('rate_card.billing_timing', 'arrears')
                ->where('rate_card.proration', false)
                ->where('rate_card.display_on_invoice', true)
                ->where('rate_card.rates_count', 1)
                ->has('rate_card.active_rate')
                ->etc();
        });

    expect(RateCard::count())->toBe(1)->and(RateCardRate::count())->toBe(1);
});

it('rejects a non-boolean proration', function (): void {
    [$organization, $apiKey] = rateCardsTestOrganization();
    $product = rateCardsTestProduct($organization);

    $this->postJson('/api/v2/rate_cards', ['rate_card' => [
        'product_code' => $product->code,
        'name' => 'API card',
        'code' => 'api_card',
        'currency' => 'EUR',
        'proration' => 'yes',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.proration.0', 'value_is_invalid')
            ->etc());
});

it('freezes billing-semantic fields once rates exist', function (): void {
    [$organization, $apiKey] = rateCardsTestOrganization();
    $product = rateCardsTestProduct($organization);

    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);
    RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'effective_from' => \Illuminate\Support\Facades\Date::today(),
    ]);

    $this->patchJson('/api/v2/rate_cards/'.$rateCard->code, ['rate_card' => [
        'billing_timing' => 'advance',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.billing_timing.0', 'not_editable_with_rates')
            ->etc());
});

it('rejects a rate effective before today', function (): void {
    [$organization, $apiKey] = rateCardsTestOrganization();
    $product = rateCardsTestProduct($organization);

    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);

    $this->postJson('/api/v2/rate_cards/'.$rateCard->code.'/rates', ['rate' => [
        'code' => 'old',
        'effective_from' => \Illuminate\Support\Facades\Date::today()->subDays(5)->toIso8601String(),
        'rate_model' => 'standard',
        'rate_properties' => ['amount' => '10'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.effective_from.0', 'must_not_be_before_today')
            ->etc());
});

it('rejects an append before the active rate', function (): void {
    [$organization, $apiKey] = rateCardsTestOrganization();
    $product = rateCardsTestProduct($organization);

    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);
    RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'effective_from' => \Illuminate\Support\Facades\Date::today(),
    ]);

    $this->postJson('/api/v2/rate_cards/'.$rateCard->code.'/rates', ['rate' => [
        'code' => 'later',
        'effective_from' => \Illuminate\Support\Facades\Date::today()->toIso8601String(),
        'rate_model' => 'standard',
        'billing_interval_unit' => 'month',
        'rate_properties' => ['amount' => '12'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('error_details.effective_from.0', 'value_already_exist')
            ->etc());
});

it('indexes rate cards filtered by product_code', function (): void {
    [$organization, $apiKey] = rateCardsTestOrganization();
    $product = rateCardsTestProduct($organization);

    RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
    ]);

    $this->getJson('/api/v2/rate_cards?product_code='.$product->code, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->count('rate_cards', 1)
            ->has('meta')
            ->etc());
});

it('destroys a rate card', function (): void {
    [$organization, $apiKey] = rateCardsTestOrganization();
    $product = rateCardsTestProduct($organization);

    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
    ]);

    $this->deleteJson('/api/v2/rate_cards/'.$rateCard->code, [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    expect($rateCard->fresh()->trashed())->toBeTrue();
});

it('returns 403 without the product_catalog feature flag', function (): void {
    $organization = Organization::factory()->create(['feature_flags' => []]);
    $apiKey = $organization->apiKeys()->first();

    $this->getJson('/api/v2/rate_cards', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden();
});
