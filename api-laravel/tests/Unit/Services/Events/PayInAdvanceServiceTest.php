<?php

declare(strict_types=1);

require_once __DIR__.'/../Wallets/WalletsTestHelpers.php';

uses()->group(
    'ledger:svc:Events.PayInAdvanceService',
    'ledger:job:Events.PayInAdvanceJob',
    'ledger:job:Fees.CreatePayInAdvanceJob',
    'ledger:job:Invoices.CreatePayInAdvanceChargeJob',
    'ledger:svc:Fees.CreatePayInAdvanceService',
    'ledger:svc:Invoices.CreatePayInAdvanceChargeService',
);

use App\Models\Fee;
use App\Models\Event;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Jobs\Events\PayInAdvanceJob;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Fees\CreatePayInAdvanceJob;
use App\Services\Events\PostProcessService;
use App\Services\Events\PayInAdvanceService;
use App\Services\Fees\CreatePayInAdvanceService;
use App\Jobs\Invoices\CreatePayInAdvanceChargeJob;
use App\Services\Invoices\CreatePayInAdvanceChargeService;

/**
 * Port of Rails' spec/services/events/pay_in_advance_service_spec.rb (the
 * scenarios the port supports): the event is fanned out to one job per
 * matching pay-in-advance charge, idempotent per transaction id.
 */
function piaMetric(bool $countAgg = false): BillableMetric
{
    return BillableMetric::factory()->create([
        'code' => 'api_calls',
        'field_name' => $countAgg ? null : 'calls',
        'aggregation_type' => $countAgg ? 0 : 1,
    ]);
}

function piaSubscription(BillableMetric $metric): Subscription
{
    $customer = Customer::factory()->create(['organization_id' => $metric->organization_id]);
    $start = Carbon\CarbonImmutable::now()->utc()->subDays(5)->startOfDay();

    return Subscription::factory()->for($customer)->create([
        'organization_id' => $metric->organization_id,
        'external_id' => 'pia-sub-1',
        'started_at' => $start,
        'subscription_at' => $start,
        'activated_at' => $start,
    ]);
}

function piaCharge(BillableMetric $metric, Subscription $subscription, bool $invoiceable): Charge
{
    return Charge::factory()->standard()->create([
        'organization_id' => $metric->organization_id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $subscription->plan_id,
        'pay_in_advance' => true,
        'invoiceable' => $invoiceable,
        'properties' => ['amount' => '1'],
    ]);
}

function piaEvent(Subscription $subscription, array $properties = ['calls' => 10]): Event
{
    return Event::factory()->create([
        'organization_id' => $subscription->organization_id,
        'external_subscription_id' => $subscription->external_id,
        'code' => 'api_calls',
        'timestamp' => now()->utc(),
        'properties' => $properties,
    ]);
}

it('dispatches the fee job for a non-invoiceable pay-in-advance charge', function (): void {
    Queue::fake();

    $metric = piaMetric();
    $subscription = piaSubscription($metric);
    piaCharge($metric, $subscription, invoiceable: false);
    $event = piaEvent($subscription);

    PayInAdvanceService::call(event: $event)->raiseIfError();

    Queue::assertPushed(CreatePayInAdvanceJob::class);
    Queue::assertNotPushed(CreatePayInAdvanceChargeJob::class);
});

it('dispatches the invoice job for an invoiceable pay-in-advance charge', function (): void {
    Queue::fake();

    $metric = piaMetric();
    $subscription = piaSubscription($metric);
    piaCharge($metric, $subscription, invoiceable: true);
    $event = piaEvent($subscription);

    PayInAdvanceService::call(event: $event)->raiseIfError();

    Queue::assertPushed(CreatePayInAdvanceChargeJob::class);
});

it('is idempotent per event transaction id', function (): void {
    Queue::fake();

    $metric = piaMetric();
    $subscription = piaSubscription($metric);
    piaCharge($metric, $subscription, invoiceable: false);
    $event = piaEvent($subscription);

    // Rails: already_processed? — a fee already billed for the transaction
    // id short-circuits the fan-out.
    Fee::factory()->create([
        'organization_id' => $subscription->organization_id,
        'invoice_id' => App\Models\Invoice::factory()->create([
            'organization_id' => $subscription->organization_id,
            'customer_id' => $subscription->customer_id,
        ])->id,
        'pay_in_advance_event_transaction_id' => $event->transaction_id,
        'pay_in_advance' => true,
    ]);

    PayInAdvanceService::call(event: $event);

    Queue::assertNothingPushed();
});

it('ignores events whose metric field is absent', function (): void {
    Queue::fake();

    $metric = piaMetric();
    $subscription = piaSubscription($metric);
    piaCharge($metric, $subscription, invoiceable: false);
    $event = piaEvent($subscription, properties: ['other_field' => 1]);

    PayInAdvanceService::call(event: $event);

    Queue::assertNothingPushed();
});

it('wires from the post-process step for a pay-in-advance metric', function (): void {
    Queue::fake();

    $metric = piaMetric();
    $subscription = piaSubscription($metric);
    piaCharge($metric, $subscription, invoiceable: false);
    $event = piaEvent($subscription);

    // The refresh flag is a no-op without an active wallet.
    App\Models\Wallet::factory()->forCustomer($subscription->customer)->create();

    PostProcessService::call(event: $event);

    // The refresh flag is raised for the wallet chain...
    expect($subscription->customer->refresh()->awaiting_wallet_refresh)->toBeTrue();

    // ...and the pay-in-advance billing is scheduled.
    Queue::assertPushed(PayInAdvanceJob::class);
});

it('bills a non-invoiceable event into a standalone fee with the cached aggregation', function (): void {
    $metric = piaMetric();
    $subscription = piaSubscription($metric);
    $charge = piaCharge($metric, $subscription, invoiceable: false);
    $event = piaEvent($subscription);

    $arguments = new App\Services\Events\PayInAdvanceArguments(
        chargeId: (string) $charge->id,
        eventId: (string) $event->id,
    );

    CreatePayInAdvanceService::call(
        meteredItem: $arguments->meteredItem(),
        billingContext: $arguments->billingContext(),
        event: $event,
    )->raiseIfError();

    $fee = Fee::query()
        ->where('pay_in_advance_event_transaction_id', $event->transaction_id)
        ->first();

    expect($fee)->not->toBeNull()
        ->and($fee->pay_in_advance)->toBeTrue()
        ->and($fee->invoice_id)->toBeNull()
        ->and((int) $fee->amount_cents)->toBe(1000)
        ->and($fee->charge_id)->toBe($charge->id)
        ->and($fee->subscription_id)->toBe($subscription->id);

    // The aggregation cache row the current-usage paths read back.
    $cached = App\Models\CachedAggregation::query()
        ->where('event_transaction_id', $event->transaction_id)
        ->first();

    expect($cached)->not->toBeNull()
        ->and(App\Support\MoneyMath::compare((string) $cached->current_aggregation, '10'))->toBe(0)
        ->and(App\Support\MoneyMath::compare((string) $cached->max_aggregation, '10'))->toBe(0);

    // A second event bills only its own increment (the cached row nets the
    // first one out of the period total).
    $secondEvent = piaEvent($subscription, ['calls' => 4]);

    $secondArguments = new App\Services\Events\PayInAdvanceArguments(
        chargeId: (string) $charge->id,
        eventId: (string) $secondEvent->id,
    );

    CreatePayInAdvanceService::call(
        meteredItem: $secondArguments->meteredItem(),
        billingContext: $secondArguments->billingContext(),
        event: $secondEvent,
    )->raiseIfError();

    $secondFee = Fee::query()
        ->where('pay_in_advance_event_transaction_id', $secondEvent->transaction_id)
        ->first();

    expect((int) $secondFee->amount_cents)->toBe(400);
});

it('bills an invoiceable event into its own finalized invoice', function (): void {
    $metric = piaMetric();
    $subscription = piaSubscription($metric);
    $charge = piaCharge($metric, $subscription, invoiceable: true);
    $event = piaEvent($subscription);

    $arguments = new App\Services\Events\PayInAdvanceArguments(
        chargeId: (string) $charge->id,
        eventId: (string) $event->id,
    );

    $result = CreatePayInAdvanceChargeService::call(
        timestamp: now()->getTimestamp(),
        meteredItem: $arguments->meteredItem(),
        billingContext: $arguments->billingContext(),
        event: $event,
    );

    $result->raiseIfError();

    $invoice = $result->invoice;

    expect($invoice)->not->toBeNull()
        ->and($invoice->fees_amount_cents)->toBe(1000)
        ->and($invoice->statusEnum())->toBe(App\Enums\InvoiceStatus::Finalized);

    $fee = $invoice->fees()->first();

    expect($fee)->not->toBeNull()
        ->and($fee->pay_in_advance)->toBeTrue()
        ->and((int) $fee->amount_cents)->toBe(1000);

    // Estimate semantics: the invoiceable event path persists the fee on the
    // invoice, and the pay_in_advance_event link is preserved.
    expect($fee->pay_in_advance_event_transaction_id)->toBe($event->transaction_id);
});
