<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Enums\InvoiceStatus;
use App\Services\Fees\ChargeService;
use App\Models\BillingPeriodBoundaries;
use App\Services\Fees\CreateTrueUpService;
use App\Services\Fees\ChargeService\Options;
use App\Services\Fees\ChargeService\MeteredItem;

/**
 * Seeds the metered input for the true-up fixture: one event inside the
 * billed August period carrying `$sum` units over the metric's field name.
 * Since finding 12 closed, Fees\ChargeService aggregates the events LIVE —
 * cached_aggregations rows are ignored on the arrears periodic path.
 */
function trueUpEvents(array $f, int $sum): void
{
    App\Models\Event::factory()->create([
        'organization_id' => $f['organization']->id,
        'external_subscription_id' => 'sub-trueup-1',
        'transaction_id' => 'tr-trueup-1',
        'code' => $f['metric']->code,
        'timestamp' => '2023-08-15 00:00:00',
        'properties' => ['value' => $sum],
    ]);
}

/**
 * Port of spec/services/fees/create_true_up_service_spec.rb — the minimum
 * commitment true-up fee built when usage did not reach min_amount_cents —
 * plus the branch through Fees\ChargeService (spec/services/fees/
 * charge_service_spec.rb true-up contexts).
 *
 * Not ported (pricing units / billing segments are not in M1): the
 * billing-segment and applied-pricing-unit contexts of the Rails spec.
 */
function trueUpFixture(array $chargeOverrides = [], array $customerOverrides = []): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(array_merge([
        'organization_id' => $organization->id,
    ], $customerOverrides));
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $metric = App\Models\BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'aggregation_type' => 1,
        'field_name' => 'value',
    ]);
    $charge = App\Models\Charge::factory()->create(array_merge([
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '1'],
        'min_amount_cents' => 1000,
        'invoiceable' => true,
        'pay_in_advance' => false,
    ], $chargeOverrides));
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'active',
        'external_id' => 'sub-trueup-1',
    ]);

    return compact('organization', 'customer', 'plan', 'metric', 'charge', 'subscription');
}

function trueUpFee(array $f, int $amountCents, array $properties): Fee
{
    return Fee::factory()->chargeFee()->create([
        'invoice_id' => null,
        'subscription_id' => $f['subscription']->id,
        'organization_id' => $f['organization']->id,
        'billing_entity_id' => $f['customer']->billing_entity_id,
        'charge_id' => $f['charge']->id,
        'invoiceable_type' => 'Charge',
        'invoiceable_id' => $f['charge']->id,
        'amount_cents' => $amountCents,
        'precise_amount_cents' => (string) $amountCents,
        'amount_currency' => 'EUR',
        'properties' => $properties,
    ]);
}

function fullMonthProperties(): array
{
    return [
        'from_datetime' => '2023-08-01 00:00:00',
        'to_datetime' => '2023-08-31 23:59:59',
        'charges_from_datetime' => '2023-08-01 00:00:00',
        'charges_to_datetime' => '2023-08-31 23:59:59',
        'charges_duration' => 31,
    ];
}

it('does not instantiate a true-up fee when the fee is nil', function (): void {
    $result = CreateTrueUpService::call(fee: null, usedAmountCents: 700, usedPreciseAmountCents: '700');

    expect($result->success())->toBeTrue()
        ->and($result->true_up_fee)->toBeNull();
})->group('ledger:svc:Fees.CreateTrueUpService');

it('does not instantiate a true-up fee when usage reached the minimum', function (): void {
    $f = trueUpFixture();
    $fee = trueUpFee($f, 1000, fullMonthProperties());

    $result = CreateTrueUpService::call(fee: $fee, usedAmountCents: 1000, usedPreciseAmountCents: '1000');

    expect($result->true_up_fee)->toBeNull();
})->group('ledger:svc:Fees.CreateTrueUpService');

it('does not instantiate a true-up fee when usage exceeded the minimum', function (): void {
    $f = trueUpFixture();
    $fee = trueUpFee($f, 1500, fullMonthProperties());

    $result = CreateTrueUpService::call(fee: $fee, usedAmountCents: 1500, usedPreciseAmountCents: '1500');

    expect($result->true_up_fee)->toBeNull();
})->group('ledger:svc:Fees.CreateTrueUpService');

it('instantiates a true-up fee for the missing amount', function (): void {
    $f = trueUpFixture();
    $fee = trueUpFee($f, 700, fullMonthProperties());

    $result = CreateTrueUpService::call(fee: $fee, usedAmountCents: 700, usedPreciseAmountCents: '700');

    $trueUp = $result->true_up_fee;
    expect($trueUp)->not->toBeNull()
        ->and($trueUp->exists)->toBeFalse() // Rails: be_new_record
        ->and($trueUp->subscription_id)->toBe($fee->subscription_id)
        ->and($trueUp->charge_id)->toBe($fee->charge_id)
        ->and($trueUp->amount_currency)->toBe($fee->amount_currency)
        ->and($trueUp->fee_type->value)->toBe($fee->fee_type->value)
        ->and($trueUp->payment_status->value)->toBe($fee->payment_status->value)
        ->and((int) $trueUp->amount_cents)->toBe(300)
        ->and((float) $trueUp->precise_amount_cents)->toBe(300.0)
        ->and((string) $trueUp->units)->toBe('1')
        ->and((string) $trueUp->total_aggregated_units)->toBe('1')
        ->and($trueUp->events_count)->toBe(0)
        ->and($trueUp->charge_filter_id)->toBeNull()
        ->and((int) $trueUp->unit_amount_cents)->toBe(300)
        ->and((float) $trueUp->precise_unit_amount)->toBe(3.0)
        ->and($trueUp->true_up_parent_fee_id)->toBe($fee->id);
})->group('ledger:svc:Fees.CreateTrueUpService');

it('keeps rounded and precise amounts independent', function (): void {
    $f = trueUpFixture();
    $fee = trueUpFee($f, 700, fullMonthProperties());

    $result = CreateTrueUpService::call(fee: $fee, usedAmountCents: 700, usedPreciseAmountCents: '700.25');

    expect((int) $result->true_up_fee->amount_cents)->toBe(300)
        ->and((float) $result->true_up_fee->precise_amount_cents)->toBe(299.75)
        ->and((int) $result->true_up_fee->unit_amount_cents)->toBe(300)
        ->and(round((float) $result->true_up_fee->precise_unit_amount, 4))->toBe(2.9975);
})->group('ledger:svc:Fees.CreateTrueUpService');

it('prorates the minimum over the billed days', function (): void {
    $f = trueUpFixture();
    $fee = trueUpFee($f, 200, [
        'from_datetime' => '2022-08-01 00:00:00',
        'to_datetime' => '2022-08-15 23:59:59',
        'charges_from_datetime' => '2022-08-01 00:00:00',
        'charges_to_datetime' => '2022-08-15 23:59:59',
        'charges_duration' => 31,
    ]);

    $result = CreateTrueUpService::call(fee: $fee, usedAmountCents: 200, usedPreciseAmountCents: '200');

    // (1000 / 31 * 15) - 200 = 283.87… → 284 rounded.
    expect((int) $result->true_up_fee->amount_cents)->toBe(284)
        ->and(round((float) $result->true_up_fee->precise_amount_cents, 10))->toBe(283.8709677419);
})->group('ledger:svc:Fees.CreateTrueUpService');

it('computes the billed days in the customer timezone', function (): void {
    $f = trueUpFixture(customerOverrides: ['timezone' => 'Pacific/Fiji']);
    $fee = trueUpFee($f, 700, fullMonthProperties());

    $result = CreateTrueUpService::call(fee: $fee, usedAmountCents: 700, usedPreciseAmountCents: '700');

    expect((int) $result->true_up_fee->amount_cents)->toBe(300)
        ->and((float) $result->true_up_fee->precise_unit_amount)->toBe(3.0);
})->group('ledger:svc:Fees.CreateTrueUpService');

it('bills the true-up fee through the charge service', function (): void {
    $f = trueUpFixture();

    $invoice = App\Models\Invoice::factory()->create([
        'organization_id' => $f['organization']->id,
        'customer_id' => $f['customer']->id,
        'status' => InvoiceStatus::Generating,
        'currency' => 'EUR',
    ]);

    // 7 units x amount 1 EUR = 700 cents — below the 1000 minimum.
    trueUpEvents($f, 7);

    $boundaries = new BillingPeriodBoundaries(
        fromDatetime: Carbon\CarbonImmutable::parse('2023-08-01 00:00:00', 'UTC'),
        toDatetime: Carbon\CarbonImmutable::parse('2023-08-31 23:59:59', 'UTC'),
        chargesFromDatetime: Carbon\CarbonImmutable::parse('2023-08-01 00:00:00', 'UTC'),
        chargesToDatetime: Carbon\CarbonImmutable::parse('2023-08-31 23:59:59', 'UTC'),
        chargesDuration: 31,
        timestamp: Carbon\CarbonImmutable::parse('2023-08-01 00:00:00', 'UTC'),
    );

    $result = ChargeService::call(
        invoice: $invoice,
        meteredItem: MeteredItem::fromCharge($f['charge'], $boundaries),
        subscription: $f['subscription'],
        options: new Options(context: 'finalize', skipAdjustedFees: true),
    );

    expect($result->success())->toBeTrue();

    $fees = $result->fees;
    expect($fees)->toHaveCount(2);

    $chargeFee = collect($fees)->first(fn (Fee $fee) => $fee->true_up_parent_fee_id === null);
    $trueUpFee = collect($fees)->first(fn (Fee $fee) => $fee->true_up_parent_fee_id !== null);

    expect((int) $chargeFee->amount_cents)->toBe(700)
        ->and($chargeFee->exists)->toBeTrue()
        ->and((int) $trueUpFee->amount_cents)->toBe(300)
        ->and($trueUpFee->exists)->toBeTrue() // persisted — a true-up fee is never dropped
        ->and($trueUpFee->true_up_parent_fee_id)->toBe($chargeFee->id)
        ->and($trueUpFee->events_count)->toBe(0)
        ->and((string) $trueUpFee->units)->toBe('1')
        ->and($trueUpFee->invoice_id)->toBe($invoice->id);
})->group('ledger:svc:Fees.CreateTrueUpService');

it('does not bill a true-up through the charge service when the minimum is met', function (): void {
    $f = trueUpFixture();

    $invoice = App\Models\Invoice::factory()->create([
        'organization_id' => $f['organization']->id,
        'customer_id' => $f['customer']->id,
        'status' => InvoiceStatus::Generating,
        'currency' => 'EUR',
    ]);

    // 12 units x 1 EUR = 1200 cents — above the 1000 minimum.
    trueUpEvents($f, 12);

    $boundaries = new BillingPeriodBoundaries(
        fromDatetime: Carbon\CarbonImmutable::parse('2023-08-01 00:00:00', 'UTC'),
        toDatetime: Carbon\CarbonImmutable::parse('2023-08-31 23:59:59', 'UTC'),
        chargesFromDatetime: Carbon\CarbonImmutable::parse('2023-08-01 00:00:00', 'UTC'),
        chargesToDatetime: Carbon\CarbonImmutable::parse('2023-08-31 23:59:59', 'UTC'),
        chargesDuration: 31,
        timestamp: Carbon\CarbonImmutable::parse('2023-08-01 00:00:00', 'UTC'),
    );

    $result = ChargeService::call(
        invoice: $invoice,
        meteredItem: MeteredItem::fromCharge($f['charge'], $boundaries),
        subscription: $f['subscription'],
        options: new Options(context: 'finalize', skipAdjustedFees: true),
    );

    expect($result->success())->toBeTrue()
        ->and($result->fees)->toHaveCount(1)
        ->and((int) $result->fees[0]->amount_cents)->toBe(1200)
        ->and($result->fees[0]->true_up_parent_fee_id)->toBeNull();
})->group('ledger:svc:Fees.CreateTrueUpService');
