<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/order_forms',
    'ledger:rest:GET:/api/v1/order_forms/:id',
    'ledger:rest:POST:/api/v1/order_forms/:id/mark_as_signed',
    'ledger:rest:POST:/api/v1/order_forms/:id/void',
    'ledger:rest:GET:/api/v2/order_forms',
    'ledger:rest:GET:/api/v2/order_forms/:id',
    'ledger:rest:POST:/api/v2/order_forms/:id/mark_as_signed',
    'ledger:rest:POST:/api/v2/order_forms/:id/void',
);

use App\Models\Order;
use App\Models\Quote;
use App\Models\Customer;
use App\Models\OrderForm;
use App\Models\Organization;
use App\Models\QuoteVersion;

/**
 * Ports of Rails' spec/requests/api/v1/order_forms_controller_spec.rb — the
 * sign / void handoff to the orders execution.
 */
function orderFormsEndpointOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function generatedOrderFormFixture(Organization $organization): OrderForm
{
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $quote = Quote::factory()->forCustomer($customer)->orderType('one_off')->create();

    $quoteVersion = QuoteVersion::factory()
        ->forQuote($quote)
        ->approved()
        ->withOneOffBillingItems()
        ->create();

    return OrderForm::factory()
        ->forCustomer($customer)
        ->forQuoteVersion($quoteVersion)
        ->create();
}

/**
 * Rails: ensure the approve chain ran — the order form hangs off an approved
 * version, so the version must carry approved_at (the DB check enforces it).
 */
beforeEach(fn (): null => config(['lago.license' => 'premium-license-token']) ?: null);

it('returns a list of order forms', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $orderForm = generatedOrderFormFixture($organization);

    $this->getJson('/api/v1/order_forms', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($orderForm): void {
        $json
            ->where('order_forms.0.lago_id', $orderForm->id)
            ->where('order_forms.0.number', $orderForm->number)
            ->where('order_forms.0.status', 'generated')
            ->where('order_forms.0.lago_quote_version_id', $orderForm->quote_version_id)
            ->where('order_forms.0.lago_quote_id', $orderForm->quoteVersion->quote_id)
            ->etc();
    });
});

it('filters order forms by status and number', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $orderForm = generatedOrderFormFixture($organization);

    $this->getJson('/api/v1/order_forms?status=generated&number='.$orderForm->number, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('order_forms.0.lago_id', $orderForm->id);

    $this->getJson('/api/v1/order_forms?status=signed', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('order_forms', []);
});

it('returns a single order form', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $orderForm = generatedOrderFormFixture($organization);

    $this->getJson("/api/v1/order_forms/{$orderForm->id}", [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('order_form.lago_id', $orderForm->id);
});

it('marks a generated order form as signed and creates the order', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $orderForm = generatedOrderFormFixture($organization);

    $this->postJson("/api/v1/order_forms/{$orderForm->id}/mark_as_signed", [
        'order_form' => ['execution_mode' => 'order_only'],
    ], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($orderForm): void {
        $json
            ->where('order_form.lago_id', $orderForm->id)
            ->where('order_form.status', 'signed')
            ->etc();
    });

    $orderForm->refresh();

    expect($orderForm->status)->toBe('signed')
        ->and($orderForm->signed_at)->not->toBeNull();

    $order = Order::query()->where('order_form_id', $orderForm->id)->first();

    expect($order)->not->toBeNull()
        ->and($order->status?->label())->toBe('created')
        ->and($order->execution_mode?->label())->toBe('order_only')
        ->and($order->orderType())->toBe('one_off')
        ->and($order->currency())->toBe('EUR');
});

it('refuses to sign an order form twice', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->create();
    $quoteVersion = QuoteVersion::factory()->forQuote($quote)->approved()->withOneOffBillingItems()->create();
    $orderForm = OrderForm::factory()->forCustomer($customer)->forQuoteVersion($quoteVersion)->signed()->create();

    $this->postJson("/api/v1/order_forms/{$orderForm->id}/mark_as_signed", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertUnprocessable()->assertJsonPath('error_details.status', ['not_signable']);
});

it('refuses an execution mode without a mode when execute_at is set', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $orderForm = generatedOrderFormFixture($organization);

    $this->postJson("/api/v1/order_forms/{$orderForm->id}/mark_as_signed", [
        'order_form' => ['execute_at' => now()->addMonth()->toIso8601String()],
    ], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertUnprocessable()->assertJsonPath('error_details.execution_mode', ['value_is_mandatory']);
});

it('refuses an execute_at before the deal expiration', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $orderForm = generatedOrderFormFixture($organization);
    $orderForm->quoteVersion->billing_items = [
        'addOns' => [[
            'id' => Illuminate\Support\Str::uuid(),
            'payload' => ['code' => 'test_add_on', 'units' => 1],
        ]],
    ];
    $orderForm->quoteVersion->save();

    $this->postJson("/api/v1/order_forms/{$orderForm->id}/mark_as_signed", [
        'order_form' => [
            'execution_mode' => 'order_only',
            'execute_at' => Illuminate\Support\Str::uuid(), // unparseable → invalid_date
        ],
    ], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertUnprocessable()->assertJsonPath('error_details.execute_at', ['invalid_date']);
});

it('voids a generated order form and cascades onto the version', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $orderForm = generatedOrderFormFixture($organization);

    $this->postJson("/api/v1/order_forms/{$orderForm->id}/void", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('order_form.status', 'voided');

    $orderForm->refresh();

    expect($orderForm->void_reason)->toBe('manual')
        ->and($orderForm->voided_at)->not->toBeNull()
        // The cascade: the approved version is voided with cascade_of_voided.
        ->and($orderForm->quoteVersion->refresh()->status)->toBe('voided')
        ->and($orderForm->quoteVersion->void_reason)->toBe('cascade_of_voided');
});

it('refuses to void a signed order form', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->create();
    $quoteVersion = QuoteVersion::factory()->forQuote($quote)->approved()->withOneOffBillingItems()->create();
    $orderForm = OrderForm::factory()->forCustomer($customer)->forQuoteVersion($quoteVersion)->signed()->create();

    $this->postJson("/api/v1/order_forms/{$orderForm->id}/void", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertUnprocessable()->assertJsonPath('error_details.status', ['not_voidable']);
});

it('returns forbidden when the order_forms flag is disabled', function (): void {
    [$organization, $apiKey] = orderFormsEndpointOrganization();

    $this->getJson('/api/v1/order_forms', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()->assertJson(['code' => 'feature_unavailable']);
});
