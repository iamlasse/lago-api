<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Jobs\BillSubscriptionJob;
use App\Models\CachedAggregation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use App\Services\Failures\FailedResult;

/**
 * Port of spec/jobs/bill_subscription_job_spec.rb #perform scenarios, run
 * against the REAL Invoices::SubscriptionService pipeline (Rails mocks the
 * service; here the seeded-data run doubles as the reachability test for
 * svc:Invoices.{SubscriptionService,CreateGeneratingService,
 * IssuingDateService,TransitionToFinalStatusService,FinalizeService}).
 *
 * The retry-with-invoice / raise-on-failure branches live in
 * BillSubscriptionJobRetryTest.php, which stubs the service and therefore
 * runs in an isolated child process (see that file for why).
 */
function billJobFixture(array $customerOverrides = [], array $planOverrides = []): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(array_merge([
        'organization_id' => $organization->id,
    ], $customerOverrides));
    $plan = App\Models\Plan::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'interval' => 'monthly',
        'pay_in_advance' => false,
    ], $planOverrides));
    $metric = App\Models\BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'aggregation_type' => 1, // sum_agg
        'recurring' => false,
        'field_name' => 'value',
    ]);
    $charge = App\Models\Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '1'],
        'invoiceable' => true,
        'pay_in_advance' => false,
    ]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'active',
        'external_id' => 'sub-job-1',
        'billing_time' => 'calendar',
        'started_at' => '2025-01-01 00:00:00',
        'activated_at' => '2025-01-01 00:00:00',
        'subscription_at' => '2025-01-01 00:00:00',
    ]);

    return compact('organization', 'customer', 'plan', 'metric', 'charge', 'subscription');
}

function billJobTimestamp(): int
{
    // 2026-10-01 12:00:00 UTC — a monthly calendar billing day.
    return Carbon\CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC')->getTimestamp();
}

function billJobRun(array $f, ?string $invoiceId = null): void
{
    (new BillSubscriptionJob(
        [$f['subscription']],
        billJobTimestamp(),
        'subscription_periodic',
        $invoiceId,
    ))->handle();
}

it('runs the full pipeline: generating invoice, fees, totals, then finalizes', function (): void {
    $f = billJobFixture();

    CachedAggregation::query()->create([
        'organization_id' => $f['organization']->id,
        'charge_id' => $f['charge']->id,
        'external_subscription_id' => 'sub-job-1',
        'timestamp' => '2026-10-01 00:00:00',
        'current_aggregation' => '10',
        'grouped_by' => [],
        'presentation_breakdowns' => [],
    ]);

    Bus::fake([BillSubscriptionJob::class]);
    billJobRun($f);

    $invoice = Invoice::query()->where('customer_id', $f['customer']->id)->sole();

    expect($invoice->statusEnum()->label())->toBe('finalized');

    // Subscription fee (plan amount) + charge fee (10 units x 100 cents).
    expect((int) $invoice->fees_amount_cents)->toBe(2000)
        ->and((int) $invoice->total_amount_cents)->toBe(2000)
        ->and((int) $invoice->taxes_amount_cents)->toBe(0)
        ->and($invoice->finalized_at)->not->toBeNull()
        ->and($invoice->invoiceSubscriptions)->toHaveCount(1)
        ->and($invoice->invoiceSubscriptions[0]->recurring)->toBeTrue()
        ->and($invoice->invoiceSubscriptions[0]->invoicing_reason)->toBe('subscription_periodic');

    // Boundary rows: calendar monthly, pay-in-arrears — the September period.
    $boundaries = $invoice->invoiceSubscriptions[0];
    expect($boundaries->from_datetime->toDateString())->toBe('2026-09-01')
        ->and($boundaries->to_datetime->toDateString())->toBe('2026-09-30');

    // Success — no retry dispatch.
    Bus::assertNotDispatched(BillSubscriptionJob::class);
})->group('ledger:job:BillSubscriptionJob', 'ledger:svc:Invoices.SubscriptionService');

it('keeps the invoice in draft when the customer has an invoice grace period', function (): void {
    $f = billJobFixture(customerOverrides: ['invoice_grace_period' => 3]);

    CachedAggregation::query()->create([
        'organization_id' => $f['organization']->id,
        'charge_id' => $f['charge']->id,
        'external_subscription_id' => 'sub-job-1',
        'timestamp' => '2026-10-01 00:00:00',
        'current_aggregation' => '10',
        'grouped_by' => [],
        'presentation_breakdowns' => [],
    ]);

    Bus::fake([BillSubscriptionJob::class]);
    billJobRun($f);

    $invoice = Invoice::query()->where('customer_id', $f['customer']->id)->sole();

    // Fees were computed (context "draft") but the invoice stays draft.
    expect($invoice->statusEnum()->label())->toBe('draft')
        ->and((int) $invoice->fees_amount_cents)->toBe(2000)
        // Issuing date shifted by the grace period; finalization expected 3 days out.
        ->and($invoice->issuing_date->toDateString())->toBe('2026-10-04')
        ->and($invoice->expected_finalization_date->toDateString())->toBe('2026-10-04')
        ->and($invoice->finalized_at)->toBeNull();

    Bus::assertNotDispatched(BillSubscriptionJob::class);
})->group('ledger:job:BillSubscriptionJob');

it('raises and creates no second invoice when the period was already billed', function (): void {
    $f = billJobFixture();

    billJobRun($f);

    $invoiceCount = Invoice::query()->where('customer_id', $f['customer']->id)->count();
    expect($invoiceCount)->toBe(1);

    Bus::fake([BillSubscriptionJob::class]);

    // Second run on the same day: duplicated_invoices failure → raise.
    expect(fn () => billJobRun($f))->toThrow(FailedResult::class);

    expect(Invoice::query()->where('customer_id', $f['customer']->id)->count())->toBe(1);

    // The failure carries no generating invoice — the job raises instead of retrying.
    Bus::assertNotDispatched(BillSubscriptionJob::class);
})->group('ledger:job:BillSubscriptionJob');

it('does not bill when no subscription is active for a periodic run', function (): void {
    $f = billJobFixture();
    $f['subscription']->update(['status' => 'canceled']);

    Bus::fake([BillSubscriptionJob::class]);
    billJobRun($f);

    expect(Invoice::query()->where('customer_id', $f['customer']->id)->count())->toBe(0);

    Bus::assertNotDispatched(BillSubscriptionJob::class);
})->group('ledger:job:BillSubscriptionJob');

it('skips execution while the unique lock is held and runs once released', function (): void {
    $f = billJobFixture();

    $job = new BillSubscriptionJob([$f['subscription']], billJobTimestamp(), 'subscription_periodic');
    $middleware = $job->middleware()[0];

    expect($middleware)->toBeInstanceOf(App\Jobs\Middleware\UniqueJob::class)
        ->and($job->uniqueFor())->toBe(12 * 3600);

    $key = 'unique:job:BillSubscriptionJob:'.$job->uniqueKey();

    // Lock held (a concurrent biller run) → on_conflict: :log, job skipped.
    $lock = Cache::lock($key, 10);
    expect($lock->get())->toBeTrue();

    $ran = false;
    $middleware->handle($job, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeFalse();

    $lock->release();

    // Lock free → the job body runs.
    $middleware->handle($job, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeTrue();
})->group('ledger:job:BillSubscriptionJob');
