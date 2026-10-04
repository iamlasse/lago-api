<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Quote;
use App\Models\Customer;
use App\Models\OrderForm;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\QuoteVersion;
use App\Support\CurrentContext;
use App\Services\Orders\UpdateService;
use App\Services\Orders\ExecuteService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ForbiddenFailure;
use App\Services\Failures\ValidationFailure;

beforeEach(function (): void {
    CurrentContext::reset();
    config(['lago.license' => 'premium-license-token']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

function orderServiceOrganization(bool $flag = true): Organization
{
    $organization = Organization::factory()->create(
        $flag ? ['feature_flags' => ['order_forms']] : [],
    );

    return CurrentContext::$organization = $organization;
}

/**
 * The spec chain: signed order form over an approved one-off quote
 * version, the order hanging off it.
 */
function orderServiceOrder(Organization $organization, array $orderAttributes = []): Order
{
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $quote = Quote::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_type' => Quote::ORDER_TYPES['one_off'],
    ]);

    $quoteVersion = QuoteVersion::factory()->create([
        'organization_id' => $organization->id,
        'quote_id' => $quote->id,
        'status' => 'approved',
        'approved_at' => now(),
        'currency' => 'EUR',
        'billing_items' => [
            'addOns' => [
                ['id' => (string) Str::uuid(), 'payload' => ['code' => 'missing_add_on', 'units' => 1]],
            ],
        ],
    ]);

    $orderForm = OrderForm::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'quote_version_id' => $quoteVersion->id,
        'status' => 'signed',
        'signed_at' => now(),
    ]);

    return Order::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_form_id' => $orderForm->id,
        'status' => 'created',
        'execution_mode' => 'order_only',
    ], $orderAttributes));
}

// -- UpdateService -------------------------------------------------------------

it('updates the execution settings of a created order', function (): void {
    $organization = orderServiceOrganization();

    $order = orderServiceOrder($organization);

    $result = UpdateService::call(order: $order, params: [
        'execution_mode' => 'execute_in_lago',
        'execute_at' => now()->addMonth()->toISOString(),
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->order->fresh()->execution_mode->value)->toBe('execute_in_lago')
        ->and($result->order->fresh()->execute_at)->not->toBeNull();
})->group('ledger:svc:Orders.UpdateService');

it('refuses to update a non-created order', function (): void {
    $organization = orderServiceOrganization();

    $order = orderServiceOrder($organization, ['status' => 'executed', 'executed_at' => now()]);

    $result = UpdateService::call(order: $order, params: ['execution_mode' => 'order_only']);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['status' => ['not_editable']]);
});

it('requires the execution_mode when any execution setting is set', function (): void {
    $organization = orderServiceOrganization();

    $order = orderServiceOrder($organization);
    $order->execution_mode = null;
    $order->save();

    $result = UpdateService::call(order: $order, params: [
        'execute_at' => now()->addMonth()->toISOString(),
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['execution_mode' => ['value_is_mandatory']]);
});

it('rejects an unknown execution mode', function (): void {
    $organization = orderServiceOrganization();

    $order = orderServiceOrder($organization);

    $result = UpdateService::call(order: $order, params: ['execution_mode' => 'whenever']);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['execution_mode' => ['value_is_invalid']]);
});

it('rejects an execute_at in the past', function (): void {
    $organization = orderServiceOrganization();

    $order = orderServiceOrder($organization);

    $result = UpdateService::call(order: $order, params: [
        'execute_at' => now()->subDay()->toISOString(),
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['execute_at' => ['invalid_date']]);
});

it('returns not_found when the updated order does not exist', function (): void {
    orderServiceOrganization();

    $result = UpdateService::call(order: null, params: ['execution_mode' => 'order_only']);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('returns forbidden when the order_forms flag is off', function (): void {
    $organization = orderServiceOrganization(flag: false);

    $order = orderServiceOrder($organization);

    $result = UpdateService::call(order: $order, params: ['execution_mode' => 'order_only']);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ForbiddenFailure::class)
        ->and($result->getError()->code)->toBe('feature_unavailable');
});

// -- ExecuteService (dispatch + gates) -------------------------------------------

it('executes an order_only order and writes the execution record', function (): void {
    $organization = orderServiceOrganization();

    $order = orderServiceOrder($organization);

    $result = ExecuteService::call(order: $order);

    expect($result->success())->toBeTrue()
        ->and($result->order->fresh()->isExecuted())->toBeTrue()
        // order_only records nothing but the execution itself.
        ->and($result->order->execution_record['invoice_id'])->toBeNull()
        ->and($result->order->execution_record['execution_mode'])->toBe('order_only')
        // Every order type writes the same keys — a reader never tells a
        // missing key from an empty one.
        ->and($result->order->execution_record)->toHaveKeys(array_keys(Order::EXECUTION_RECORD_DEFAULTS));
})->group('ledger:svc:Orders.ExecuteService');

it('executes an order again after a failure and clears the errors', function (): void {
    $organization = orderServiceOrganization();

    $order = orderServiceOrder($organization, [
        'status' => 'failed',
        'execution_record' => ['errors' => ['currency' => ['value_is_mandatory']]],
    ]);

    $result = ExecuteService::call(order: $order);

    expect($result->success())->toBeTrue()
        ->and($result->order->fresh()->isExecuted())->toBeTrue()
        ->and($result->order->execution_record['errors'])->toBe([]);
});

it('returns the already executed order untouched', function (): void {
    $organization = orderServiceOrganization();

    $executedAt = now()->subHour();
    $order = orderServiceOrder($organization, [
        'status' => 'executed',
        'executed_at' => $executedAt,
    ]);

    $result = ExecuteService::call(order: $order);

    expect($result->success())->toBeTrue()
        ->and($result->order->fresh()->executed_at->equalTo($executedAt))->toBeTrue();
});

it('requires an execution mode before executing', function (): void {
    $organization = orderServiceOrganization();

    $order = orderServiceOrder($organization, ['execution_mode' => null]);

    $result = ExecuteService::call(order: $order);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['execution_mode' => ['value_is_mandatory']])
        // The gate leaves the order untouched (still created).
        ->and($order->fresh()->isCreated())->toBeTrue();
});

it('returns forbidden without the order_forms flag', function (): void {
    $organization = orderServiceOrganization(flag: false);

    $order = orderServiceOrder($organization);

    $result = ExecuteService::call(order: $order);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ForbiddenFailure::class)
        ->and($result->getError()->code)->toBe('feature_unavailable');
});

it('returns not_found when the order does not exist', function (): void {
    orderServiceOrganization();

    $result = ExecuteService::call(order: null);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('marks the order failed with the errors when the execution fails', function (): void {
    $organization = orderServiceOrganization();

    // execute_in_lago over a billing snapshot naming an unknown add-on —
    // the one-off billing raises not_found(add_on) inside the execution.
    $order = orderServiceOrder($organization, ['execution_mode' => 'execute_in_lago']);

    $result = ExecuteService::call(order: $order);

    expect($result->success())->toBeFalse()
        // Rails: rescue BaseService::FailedResult => e;
        // record_execution_failure!(e.result) — the caller receives the
        // nested failed result.
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        // ...while the order itself moved to failed with the trace.
        ->and($order->fresh()->isFailed())->toBeTrue()
        ->and($order->fresh()->execution_record['errors'])->toBe(['add_on_not_found']);
});
