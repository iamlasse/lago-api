<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/quote_versions/:id',
    'ledger:rest:POST:/api/v1/quote_versions/:id/approve',
    'ledger:rest:POST:/api/v1/quote_versions/:id/void',
    'ledger:rest:POST:/api/v1/quote_versions/:id/clone',
    'ledger:rest:GET:/api/v2/quote_versions/:id',
    'ledger:rest:POST:/api/v2/quote_versions/:id/approve',
    'ledger:rest:POST:/api/v2/quote_versions/:id/void',
    'ledger:rest:POST:/api/v2/quote_versions/:id/clone',
    'ledger:ser:V1.QuoteVersionSerializer'
);

use App\Models\Plan;
use App\Models\Quote;
use App\Models\Customer;
use App\Models\OrderForm;
use App\Models\Organization;
use App\Models\QuoteVersion;

/**
 * Ports of Rails' spec/requests/api/v1/quote_versions_controller_spec.rb —
 * the draft lifecycle transitions over REST.
 */
/**
 * Rails' :premium spec tag — the order_forms services gate on
 * License.premium? (a configured license key).
 */
beforeEach(fn (): null => config(['lago.license' => 'premium-license-token']) ?: null);

function quoteVersionsEndpointOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function quoteVersionFixture(Organization $organization, string $status = 'draft'): QuoteVersion
{
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'amount_currency' => 'EUR']);

    $quote = Quote::factory()->forCustomer($customer)->create();

    $quoteVersion = QuoteVersion::factory()->forQuote($quote)->withPlanBillingItems($plan)->create();

    if ($status === 'approved') {
        $quoteVersion->status = 'approved';
        $quoteVersion->approved_at = now();
        $quoteVersion->save();
    } elseif ($status === 'voided') {
        $quoteVersion->status = 'voided';
        $quoteVersion->void_reason = 'manual';
        $quoteVersion->voided_at = now();
        $quoteVersion->save();
    }

    return $quoteVersion;
}

it('shows a quote version with its content and billing items', function (): void {
    [$organization, $apiKey] = quoteVersionsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $quoteVersion = quoteVersionFixture($organization);

    $this->getJson("/api/v1/quote_versions/{$quoteVersion->id}", [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($quoteVersion): void {
        $json
            ->where('quote_version.lago_id', $quoteVersion->id)
            ->where('quote_version.version', 1)
            ->where('quote_version.status', 'draft')
            ->where('quote_version.currency', 'EUR')
            ->has('quote_version.billing_items')
            ->etc();
    });
});

it('approves a draft version and generates the order form', function (): void {
    [$organization, $apiKey] = quoteVersionsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $quoteVersion = quoteVersionFixture($organization);

    $this->postJson("/api/v1/quote_versions/{$quoteVersion->id}/approve", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($quoteVersion): void {
        $json
            ->where('quote_version.lago_id', $quoteVersion->id)
            ->where('quote_version.status', 'approved')
            ->etc();
    });

    $quoteVersion->refresh();

    expect($quoteVersion->status)->toBe('approved')
        ->and($quoteVersion->approved_at)->not->toBeNull()
        ->and($quoteVersion->mention_variables)->not->toBeNull()
        ->and(OrderForm::query()->where('quote_version_id', $quoteVersion->id)->exists())->toBeTrue();
});

it('refuses to approve twice', function (): void {
    [$organization, $apiKey] = quoteVersionsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $quoteVersion = quoteVersionFixture($organization, 'approved');

    $this->postJson("/api/v1/quote_versions/{$quoteVersion->id}/approve", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertUnprocessable()->assertJsonPath('error_details.status', ['not_approvable']);
});

it('runs the validators at approve and reports billing item errors', function (): void {
    [$organization, $apiKey] = quoteVersionsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->orderType('one_off')->create();

    // One-off deal with a missing add-on id: the structural pass passes, the
    // business one reports add_on_not_found at approve.
    $quoteVersion = QuoteVersion::factory()->forQuote($quote)->create([
        'currency' => 'EUR',
        'billing_items' => [
            'addOns' => [
                [
                    'id' => Illuminate\Support\Str::uuid(),
                    'localId' => 'local-1',
                    'type' => 'add_on',
                    'payload' => ['code' => 'missing', 'units' => 1, 'unitAmountCents' => 100, 'totalAmountCents' => 100],
                ],
            ],
        ],
    ]);

    $response = $this->postJson("/api/v1/quote_versions/{$quoteVersion->id}/approve", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertUnprocessable();

    // The error field names are flat dotted keys ("billing_items.<pointer>"),
    // exactly the shape Rails surfaces.
    expect($response->json('error_details'))
        ->toBe(['billing_items.addOns.0.id' => ['add_on_not_found']]);
});

it('voids a draft version', function (): void {
    [$organization, $apiKey] = quoteVersionsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $quoteVersion = quoteVersionFixture($organization);

    $this->postJson("/api/v1/quote_versions/{$quoteVersion->id}/void", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('quote_version.status', 'voided');

    $quoteVersion->refresh();

    expect($quoteVersion->void_reason)->toBe('manual')
        ->and($quoteVersion->voided_at)->not->toBeNull();
});

it('refuses to void an approved version with the manual reason', function (): void {
    [$organization, $apiKey] = quoteVersionsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $quoteVersion = quoteVersionFixture($organization, 'approved');

    $this->postJson("/api/v1/quote_versions/{$quoteVersion->id}/void", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertUnprocessable()->assertJsonPath('error_details.status', ['not_voidable']);
});

it('clones a voided version into a fresh draft', function (): void {
    [$organization, $apiKey] = quoteVersionsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $quoteVersion = quoteVersionFixture($organization, 'voided');

    $response = $this->postJson("/api/v1/quote_versions/{$quoteVersion->id}/clone", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk();

    $cloneId = $response->json('quote_version.lago_id');

    expect($cloneId)->not->toBe($quoteVersion->id)
        ->and($response->json('quote_version.status'))->toBe('draft')
        ->and($response->json('quote_version.version'))->toBe(2);

    $clone = QuoteVersion::query()->find($cloneId);

    expect($clone->billing_items)->toBe($quoteVersion->billing_items)
        ->and($clone->mention_variables)->toBeNull();
});

it('refuses to clone when an approved version exists', function (): void {
    [$organization, $apiKey] = quoteVersionsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    $quote = Quote::factory()->forCustomer($customer)->create();

    QuoteVersion::factory()->forQuote($quote)->withPlanBillingItems($plan)->approved()->create();

    // The approved version is not clonable while it is the live approval.
    $this->postJson('/api/v1/quote_versions/'.$quote->quoteVersions()->first()->id.'/clone', [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertUnprocessable()->assertJsonPath('error_details.status', ['not_clonable']);
});

it('returns forbidden when the order_forms flag is disabled', function (): void {
    [$organization, $apiKey] = quoteVersionsEndpointOrganization();

    $quoteVersion = quoteVersionFixture($organization);

    $this->postJson("/api/v1/quote_versions/{$quoteVersion->id}/approve", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()->assertJson(['code' => 'feature_unavailable']);
});
