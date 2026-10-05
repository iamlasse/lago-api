<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/quotes',
    'ledger:rest:GET:/api/v1/quotes/:id',
    'ledger:rest:GET:/api/v1/quotes/:id/versions',
    'ledger:rest:GET:/api/v2/quotes',
    'ledger:rest:GET:/api/v2/quotes/:id',
    'ledger:rest:GET:/api/v2/quotes/:id/versions',
    'ledger:ser:V1.QuoteSerializer'
);

use App\Models\Plan;
use App\Models\Quote;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\QuoteVersion;

/**
 * Ports of Rails' spec/requests/api/v1/quotes_controller_spec.rb and the
 * nested versions index — quotes are read-only over REST and every action
 * gates on the order_forms feature flag.
 */
/**
 * Rails' :premium spec tag — the order_forms services gate on
 * License.premium? (a configured license key).
 */
beforeEach(fn (): null => config(['lago.license' => 'premium-license-token']) ?: null);

function quotesEndpointOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

it('returns a list of quotes with the current version embedded', function (): void {
    [$organization, $apiKey] = quotesEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    $quote = Quote::factory()->forCustomer($customer)->create();
    QuoteVersion::factory()->forQuote($quote)->withPlanBillingItems($plan)->create();

    $this->getJson('/api/v1/quotes', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($quote, $customer): void {
        $json
            ->where('quotes.0.lago_id', $quote->id)
            ->where('quotes.0.number', $quote->number)
            ->where('quotes.0.order_type', $quote->order_type)
            ->where('quotes.0.lago_customer_id', $customer->id)
            ->where('quotes.0.current_version.status', 'draft')
            ->where('quotes.0.current_version.version', 1)
            ->etc();
    });
});

it('filters quotes by status of the current version', function (): void {
    [$organization, $apiKey] = quotesEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $draftQuote = Quote::factory()->forCustomer($customer)->create();
    QuoteVersion::factory()->forQuote($draftQuote)->create(['currency' => 'EUR']);

    $voidedQuote = Quote::factory()->forCustomer($customer)->create();
    QuoteVersion::factory()->forQuote($voidedQuote)->create(['currency' => 'EUR']);
    QuoteVersion::factory()->forQuote($voidedQuote)->voided()->create();

    $response = $this->getJson('/api/v1/quotes?status[]=draft', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk();

    expect($response->json('quotes.*.lago_id'))
        ->toContain($draftQuote->id)
        ->not->toContain($voidedQuote->id);
});

it('returns a single quote with owners', function (): void {
    [$organization, $apiKey] = quotesEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->create();

    $this->getJson("/api/v1/quotes/{$quote->id}", [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($quote): void {
        $json
            ->where('quote.lago_id', $quote->id)
            ->where('quote.owners', [])
            ->etc();
    });
});

it('returns not found on an unknown quote', function (): void {
    [$organization, $apiKey] = quotesEndpointOrganization(['feature_flags' => ['order_forms']]);

    $this->getJson('/api/v1/quotes/'.Illuminate\Support\Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()->assertJson(['code' => 'quote_not_found']);
});

it('returns forbidden when the order_forms flag is disabled', function (): void {
    [$organization, $apiKey] = quotesEndpointOrganization();

    $this->getJson('/api/v1/quotes', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()->assertJson(['code' => 'feature_unavailable']);
});

it('serves the same surface on v2', function (): void {
    [$organization, $apiKey] = quotesEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->create();

    $this->getJson('/api/v2/quotes/'.$quote->id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('quote.lago_id', $quote->id);
});

it('returns the paginated version history of a quote', function (): void {
    [$organization, $apiKey] = quotesEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->create();

    $first = QuoteVersion::factory()->forQuote($quote)->create(['currency' => 'EUR']);
    $second = QuoteVersion::factory()->forQuote($quote)->voided()->create(['currency' => 'EUR']);

    $this->getJson("/api/v1/quotes/{$quote->id}/versions", [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($first, $second): void {
        $json
            ->where('quote_versions.0.lago_id', $second->id)
            ->where('quote_versions.0.status', 'voided')
            ->where('quote_versions.0.void_reason', 'manual')
            ->where('quote_versions.1.lago_id', $first->id)
            ->where('quote_versions.1.status', 'draft')
            ->etc();
    });
});

it('returns not found for versions of an unknown quote', function (): void {
    [$organization, $apiKey] = quotesEndpointOrganization(['feature_flags' => ['order_forms']]);

    $this->getJson('/api/v1/quotes/'.Illuminate\Support\Str::uuid().'/versions', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});
