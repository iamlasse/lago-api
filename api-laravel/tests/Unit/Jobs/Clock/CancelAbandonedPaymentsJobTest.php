<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\CancelAbandonedPaymentsJob;
use App\Jobs\Invoices\Payments\CancelAbandonedJob;

uses()->group('ledger:job:Clock.CancelAbandonedPaymentsJob');

/**
 * Port of Rails' spec/jobs/clock/cancel_abandoned_payments_job_spec.rb —
 * the hourly sweep narrows to Stripe invoice payments in requires_action +
 * processing inside the recovery window (1 month .. 24h ago), then fans out
 * per-payment cancellation jobs with per-batch spacing.
 */

/**
 * Builds org + customer + Stripe provider + an abandoned-shaped payment on
 * a fresh invoice. Returns [$organization, $provider, $invoice].
 */
function abandonedJobSetup(): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $provider = PaymentProvider::factory()->forOrganization($organization)->create();
    $invoice = Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();

    return [$organization, $customer, $provider, $invoice];
}

function buildAbandonedPayment(Organization $organization, Customer $customer, PaymentProvider $provider, Invoice $invoice, array $overrides = []): Payment
{
    return Payment::factory()->forInvoice($invoice)->create(array_merge([
        'payment_provider_id' => $provider->id,
        'payable_payment_status' => 'processing',
        'status' => 'requires_action',
        'updated_at' => now()->subDays(2),
    ], $overrides));
}

it('enqueues a cancellation for the abandoned payment', function (): void {
    Queue::fake();
    [, $customer, $provider, $invoice] = abandonedJobSetup();
    $abandoned = buildAbandonedPayment($invoice->organization, $customer, $provider, $invoice);

    (new CancelAbandonedPaymentsJob)->handle();

    Queue::assertPushed(CancelAbandonedJob::class, fn (CancelAbandonedJob $job): bool => $job->payment->is($abandoned));
});

it('leaves a payment redirected minutes ago until the window passes', function (): void {
    Queue::fake();
    [, $customer, $provider, $invoice] = abandonedJobSetup();
    $fresh = buildAbandonedPayment($invoice->organization, $customer, $provider, $invoice, ['updated_at' => now()->subMinutes(5)]);

    (new CancelAbandonedPaymentsJob)->handle();

    Queue::assertNotPushed(CancelAbandonedJob::class, fn (CancelAbandonedJob $job): bool => $job->payment->is($fresh));
});

it('skips payments whose provider was deleted, since nothing can cancel them', function (): void {
    Queue::fake();
    [, $customer, $provider, $invoice] = abandonedJobSetup();
    $orphaned = buildAbandonedPayment($invoice->organization, $customer, $provider, $invoice);

    $provider->delete();

    (new CancelAbandonedPaymentsJob)->handle();

    Queue::assertNotPushed(CancelAbandonedJob::class, fn (CancelAbandonedJob $job): bool => $job->payment->is($orphaned));
});

it('skips manually recorded payments, since there is no provider intent to cancel', function (): void {
    Queue::fake();
    [, $customer, $provider, $invoice] = abandonedJobSetup();
    $manual = buildAbandonedPayment($invoice->organization, $customer, $provider, $invoice, [
        'payment_type' => 'manual',
        'reference' => 'wire 42',
    ]);

    (new CancelAbandonedPaymentsJob)->handle();

    Queue::assertNotPushed(CancelAbandonedJob::class, fn (CancelAbandonedJob $job): bool => $job->payment->is($manual));
});

it('does not enqueue a redirect stale beyond the recovery window', function (): void {
    Queue::fake();
    [, $customer, $provider, $invoice] = abandonedJobSetup();
    $ancient = buildAbandonedPayment($invoice->organization, $customer, $provider, $invoice, ['updated_at' => now()->subMonths(6)]);

    (new CancelAbandonedPaymentsJob)->handle();

    Queue::assertNotPushed(CancelAbandonedJob::class, fn (CancelAbandonedJob $job): bool => $job->payment->is($ancient));
});

it('skips payments on other providers, since only Stripe intents are read and cancelled here', function (): void {
    Queue::fake();
    [$organization, $customer] = abandonedJobSetup();
    $gocardless = PaymentProvider::factory()->forOrganization($organization)->create([
        'type' => 'PaymentProviders::GocardlessProvider',
        'code' => 'gocardless',
        'name' => 'GoCardless',
    ]);
    $invoice = Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();
    $elsewhere = buildAbandonedPayment($organization, $customer, $gocardless, $invoice);

    (new CancelAbandonedPaymentsJob)->handle();

    Queue::assertNotPushed(CancelAbandonedJob::class, fn (CancelAbandonedJob $job): bool => $job->payment->is($elsewhere));
});

it('delays each batch further than the last, so the work arrives as a trickle', function (): void {
    Queue::fake();
    [$organization, $customer, $provider] = abandonedJobSetup();

    // One more payment than BATCH_SIZE (100): the second batch carries the
    // +SPACING delay (Rails stubs the constants; the port creates the real
    // batch size to exercise the same math). Payments are unique per
    // payable, so each needs its own invoice; org + customer are shared.
    collect(range(1, 101))->each(function () use ($organization, $customer, $provider): void {
        $invoice = Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();

        buildAbandonedPayment($organization, $customer, $provider, $invoice);
    });

    (new CancelAbandonedPaymentsJob)->handle();

    $jobs = Queue::pushedJobs()[CancelAbandonedJob::class] ?? [];

    expect(count($jobs))->toBe(101);

    // The job's delay is stored as a Carbon point in time; measure it
    // against the fake's per-job createdAt (dispatch moment).
    $delays = array_map(function (array $entry): int {
        $delay = $entry['job']->delay;

        $at = $delay instanceof DateTimeInterface ? $delay->getTimestamp() : (int) $delay;

        return (int) round($at - $entry['createdAt']);
    }, $jobs);

    sort($delays);

    // First batch immediate, second batch a full SPACING later.
    expect($delays[0])->toBeLessThan(5);
    expect(end($delays))->toBeGreaterThan(CancelAbandonedPaymentsJob::SPACING_SECONDS - 5);
    expect(count(array_filter($delays, fn (int $d): bool => $d < 5)))->toBe(100);
});
