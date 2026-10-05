<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/orders',
    'ledger:rest:GET:/api/v1/orders/:id',
    'ledger:rest:GET:/api/v2/orders',
    'ledger:rest:GET:/api/v2/orders/:id',
    'ledger:rest:POST:/api/v1/orders/:id/execute',
    'ledger:rest:POST:/api/v2/orders/:id/execute',
);

use App\Models\AddOn;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Customer;
use App\Models\OrderForm;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\QuoteVersion;

/**
 * Port of Rails' spec/requests/api/v1/orders_controller_spec.rb — orders
 * are keyed by uuid id and every action gates on the order_forms feature
 * flag.
 */
function orderEndpointOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

/**
 * Rails' :premium spec tag — License.premium? is true while a license key
 * is configured.
 */
function withOrderPremiumLicense(callable $scenario): void
{
    config(['lago.license' => 'premium-license-token']);

    try {
        $scenario();
    } finally {
        config(['lago.license' => null]);
    }
}

/**
 * Rails' spec subject chain: a signed order form over an approved quote
 * version, the order hanging off it.
 */
function orderScenarioFixture(Organization $organization, Customer $customer, string $orderType = Quote::ORDER_TYPES['one_off']): Order
{
    $quote = Quote::factory()
        ->forCustomer($customer)
        ->orderType($orderType)
        ->create();

    $quoteVersion = QuoteVersion::factory()
        ->forQuote($quote)
        ->approved()
        ->withOneOffBillingItems()
        ->create();

    $orderForm = OrderForm::factory()
        ->forCustomer($customer)
        ->forQuoteVersion($quoteVersion)
        ->signed()
        ->create();

    return Order::factory()
        ->forCustomer($customer)
        ->forOrderForm($orderForm)
        ->executionMode('order_only')
        ->create();
}

// -- GET /api/v1/orders -------------------------------------------------------------

it('returns a list of orders', function (): void {
    [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $order = orderScenarioFixture($organization, $customer);
    $orderTwo = orderScenarioFixture($organization, $customer);
    $this->getJson('/api/v1/orders', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        // The consistent ordering (created_at desc, id asc) is not asserted
        // on uuid ids — two same-second inserts tie on created_at and uuid
        // v4 ids carry no order.
        $json->count('orders', 2)
            // Rails: expect(json[:orders].first).to have_key(:billing_snapshot).
            ->has('orders.0.billing_snapshot')
            ->etc();
    });
});

it('filters orders by status', function (): void {
    [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    orderScenarioFixture($organization, $customer);
    orderScenarioFixture($organization, $customer);

    $this->getJson('/api/v1/orders?status=created', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonCount(2, 'orders');
});

it('filters orders by order_type', function (): void {
    [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $order = orderScenarioFixture($organization, $customer);
    orderScenarioFixture($organization, $customer, Quote::ORDER_TYPES['subscription_creation']);

    $this->getJson('/api/v1/orders?order_type=one_off', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('orders.0.lago_id', $order->id);
});

it('returns forbidden on the orders index when the order_forms flag is disabled', function (): void {
    [$organization, $apiKey] = orderEndpointOrganization();

    $this->getJson('/api/v1/orders', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'feature_unavailable',
        ]);
});

// -- GET /api/v1/orders/:id -----------------------------------------------------------

it('returns an order', function (): void {
    [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $order = orderScenarioFixture($organization, $customer);

    $this->getJson('/api/v1/orders/'.$order->id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($order): void {
        $json->where('order.lago_id', $order->id)
            ->where('order.number', $order->number)
            ->where('order.status', 'created')
            ->has('order.billing_snapshot')
            ->etc();
    });
});

it('returns not_found when the order does not exist', function (): void {
    [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

    $this->getJson('/api/v1/orders/'.Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()
        ->assertExactJson([
            'status' => 404,
            'error' => 'Not Found',
            'code' => 'order_not_found',
        ]);
});

it('returns forbidden on the order show when the order_forms flag is disabled', function (): void {
    [$organization, $apiKey] = orderEndpointOrganization();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $order = orderScenarioFixture($organization, $customer);

    $this->getJson('/api/v1/orders/'.$order->id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'feature_unavailable',
        ]);
});

// -- POST /api/v1/orders/:id/execute ---------------------------------------------------

it('executes an order in order_only mode', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => 'EUR',
        ]);
        $order = orderScenarioFixture($organization, $customer);

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [], [
            'Authorization' => 'Bearer '.$apiKey->value,
        ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($order): void {
            $json->where('order.lago_id', $order->id)
                ->where('order.status', 'executed')
                ->has('order.executed_at')
                ->where('order.execution_record.execution_mode', 'order_only')
                ->etc();
        });

        expect($order->fresh()->isExecuted())->toBeTrue()
            // No invoice: an order_only execution records the execution
            // and nothing else.
            ->and($order->fresh()->execution_record['invoice_id'])->toBeNull();
    });
});

it('bills an execute_in_lago one-off order and returns the invoice it created', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => 'EUR',
        ]);
        AddOn::factory()->create([
            'organization_id' => $organization->id,
            'code' => 'test_add_on',
        ]);
        $order = orderScenarioFixture($organization, $customer);
        $order->execution_mode = 'execute_in_lago';
        $order->save();

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [], [
            'Authorization' => 'Bearer '.$apiKey->value,
        ])->assertOk();

        $order->refresh();

        expect($order->status->value)->toBe('executed')
            // THE ORDER → INVOICE SEMANTICS: an execute_in_lago one-off
            // order creates (and finalizes) the invoice immediately, inside
            // the execution — its id lands in the execution record.
            ->and($order->execution_record['invoice_id'])->not->toBeNull();
    });
});

it('returns an already executed order untouched', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => 'EUR',
        ]);
        $order = orderScenarioFixture($organization, $customer);
        $order->status = 'executed';
        $order->execution_mode = 'order_only';
        $order->executed_at = now();
        $order->save();

        $executedAt = $order->executed_at;

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [], [
            'Authorization' => 'Bearer '.$apiKey->value,
        ])->assertOk()->assertJsonPath('order.status', 'executed');

        expect($order->fresh()->executed_at->equalTo($executedAt))->toBeTrue();
    });
});

it('executes an order again after a failed execution', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => 'EUR',
        ]);
        $order = orderScenarioFixture($organization, $customer);
        $order->status = 'failed';
        $order->execution_mode = 'order_only';
        $order->execution_record = ['errors' => ['add_on_not_found']];
        $order->save();

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [], [
            'Authorization' => 'Bearer '.$apiKey->value,
        ])->assertOk()->assertJsonPath('order.status', 'executed');

        expect($order->fresh()->execution_record['errors'])->toBe([]);
    });
});

it('requires an execution mode when the order carries none', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => 'EUR',
        ]);
        $order = orderScenarioFixture($organization, $customer);
        $order->execution_mode = null;
        $order->save();

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [], [
            'Authorization' => 'Bearer '.$apiKey->value,
        ])->assertUnprocessable()
            ->assertJsonPath('error_details.execution_mode.0', 'value_is_mandatory');
    });
});

it('executes with the execution mode restated on the request', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => 'EUR',
        ]);
        $order = orderScenarioFixture($organization, $customer);
        $order->execution_mode = null;
        $order->save();

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [
            'order' => ['execution_mode' => 'order_only'],
        ], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertOk()
            ->assertJsonPath('order.status', 'executed');

        expect($order->fresh()->execution_mode->value)->toBe('order_only');
    });
});

it('rejects an invalid execution mode', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => 'EUR',
        ]);
        $order = orderScenarioFixture($organization, $customer);
        $order->execution_mode = null;
        $order->save();

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [
            'order' => ['execution_mode' => 'whenever'],
        ], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertUnprocessable()
            ->assertJsonPath('error_details.execution_mode.0', 'value_is_invalid');
    });
});

it('rejects an execution mode change on an executed order', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => 'EUR',
        ]);
        $order = orderScenarioFixture($organization, $customer);
        $order->status = 'executed';
        $order->execution_mode = 'order_only';
        $order->executed_at = now();
        $order->save();

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [
            'order' => ['execution_mode' => 'execute_in_lago'],
        ], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertUnprocessable()
            ->assertJsonPath('error_details.status.0', 'not_editable');
    });
});

it('marks the order failed and surfaces the error when the execution fails', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        // No currency anywhere: the one-off billing fails its currency
        // presence validation — the Rails spec stubs an equivalent
        // single_validation_failure onto Invoices::CreateOneOffService
        // (there: currencies_does_not_match).
        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => null,
        ]);
        $order = orderScenarioFixture($organization, $customer);
        // De-currency the deal AND the customer: CreateOneOffService then
        // fails its currency presence validation.
        $order->quoteVersion()?->update(['currency' => null]);
        $order->execution_mode = 'execute_in_lago';
        $order->save();

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [], [
            'Authorization' => 'Bearer '.$apiKey->value,
        ])->assertUnprocessable()
            ->assertJsonPath('error_details.currency.0', 'value_is_mandatory');

        expect($order->fresh()->isFailed())->toBeTrue()
            ->and($order->fresh()->execution_record['errors'])->toBe(['value_is_mandatory']);
    });
});

it('returns not_found when the executed order does not exist', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

        $this->postJson('/api/v1/orders/'.Str::uuid().'/execute', [], [
            'Authorization' => 'Bearer '.$apiKey->value,
        ])->assertNotFound();
    });
});

it('returns forbidden on execute without a premium license', function (): void {
    // The config default may carry a token (premium by default in this
    // environment) — the scenario needs the gate closed.
    config(['lago.license' => null]);

    [$organization, $apiKey] = orderEndpointOrganization(['feature_flags' => ['order_forms']]);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);
    $order = orderScenarioFixture($organization, $customer);

    $this->postJson('/api/v1/orders/'.$order->id.'/execute', [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'feature_unavailable',
        ]);
});

it('returns forbidden on execute when the order_forms flag is disabled', function (): void {
    withOrderPremiumLicense(function (): void {
        [$organization, $apiKey] = orderEndpointOrganization();

        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency' => 'EUR',
        ]);
        $order = orderScenarioFixture($organization, $customer);

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [], [
            'Authorization' => 'Bearer '.$apiKey->value,
        ])->assertForbidden()
            ->assertExactJson([
                'status' => 403,
                'error' => 'Forbidden',
                'code' => 'feature_unavailable',
            ]);
    });
});
