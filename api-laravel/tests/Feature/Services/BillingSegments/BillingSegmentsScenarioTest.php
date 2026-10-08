<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Customer;
use App\Models\RateCard;
use App\Models\CatalogPlan;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Models\PlanRateCard;
use App\Models\RateCardRate;
use App\Models\BillingSegment;
use App\Models\ContractRateCard;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\CreateBillingSegmentsJob;
use App\Services\Contracts\CreateService as ContractsCreateService;

/**
 * Port of spec/scenarios/billing_segments/produce_and_invoice_spec.rb and
 * advance_billing_on_contract_creation_spec.rb — the two halves meeting: the
 * hourly clock produces a customer's due segments, and the consumer turns
 * them into an invoice in the same run. Everything between is real — the
 * calendar, the selection, the writer, the clock, the fee computation.
 */
/** Rails `perform_enqueued_jobs`: run the jobs the clock fanned out. */
function runPushedBillingJobs(): void
{
    // Re-snapshot per phase: ScheduleJob chains ProcessJob at runtime.
    foreach (Queue::pushedJobs()[App\Jobs\BillingSegments\ScheduleJob::class] ?? [] as $entry) {
        $entry['job']->handle();
    }
    foreach (Queue::pushedJobs()[App\Jobs\BillingSegments\ProcessJob::class] ?? [] as $entry) {
        $entry['job']->handle();
    }
}

function billingScenarioFixedProduct(Organization $organization): Product
{
    return Product::factory()->fixed()->create(['organization_id' => $organization->id]);
}

function billingScenarioContract(Organization $organization, Customer $customer, RateCard $rateCard, int $units): ContractRateCard
{
    $contractRateCard = ContractRateCard::factory()->create([
        'organization_id' => $organization->id,
        'contract_id' => App\Models\Contract::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'billing_entity_id' => $organization->defaultBillingEntity->id,
            'started_at' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        ])->id,
        'rate_card_id' => $rateCard->id,
        'units' => $units,
        'effective_date' => '2026-01-01',
        'billing_anchor_date' => '2026-01-01',
        'next_billing_at' => CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'),
    ]);

    RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'effective_from' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        'rate_model' => 'standard',
        'rate_properties' => ['amount' => '50'],
        'billing_interval_count' => 1,
        'billing_interval_unit' => 'month',
    ]);

    return $contractRateCard;
}

it('bills January in arrears on the tick that follows it', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-01 00:12:00', 'UTC'));

    $organization = Organization::factory()->create(['webhook_url' => null]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'UTC',
        'currency' => 'EUR',
    ]);
    $product = billingScenarioFixedProduct($organization);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'billing_timing' => 'arrears',
        'proration' => false,
    ]);
    billingScenarioContract($organization, $customer, $rateCard, units: 3);

    (new CreateBillingSegmentsJob)->handle();
    runPushedBillingJobs();

    $invoice = Invoice::query()->where('customer_id', $customer->id)->sole();

    expect($invoice->status->label())->toBe('finalized')
        ->and($invoice->currency)->toBe('EUR')
        ->and((int) $invoice->total_amount_cents)->toBe(15_000);

    $segment = BillingSegment::query()->where('customer_id', $customer->id)->sole();

    expect($segment->started_at->equalTo(CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC')))->toBeTrue()
        ->and($segment->ended_at->equalTo(BillingSegment::inclusiveEnd(CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'))))->toBeTrue()
        ->and($segment->billing_at->equalTo(CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC')))->toBeTrue()
        ->and($segment->currency)->toBe('EUR')
        ->and($segment->status->value)->toBe('done')
        ->and($segment->invoice_id)->toBe($invoice->id);

    CarbonImmutable::setTestNow();
});

it('moves the clock on, so the next tick bills February and not January again', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-01 00:12:00', 'UTC'));

    $organization = Organization::factory()->create(['webhook_url' => null]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'UTC',
        'currency' => 'EUR',
    ]);
    $product = billingScenarioFixedProduct($organization);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'billing_timing' => 'arrears',
        'proration' => false,
    ]);
    $card = billingScenarioContract($organization, $customer, $rateCard, units: 3);

    (new CreateBillingSegmentsJob)->handle();
    runPushedBillingJobs();

    expect(CarbonImmutable::parse($card->fresh()->next_billing_at)->equalTo(CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC')))->toBeTrue();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 00:12:00', 'UTC'));

    (new CreateBillingSegmentsJob)->handle();
    runPushedBillingJobs();

    expect(BillingSegment::query()->where('customer_id', $customer->id)->orderBy('started_at')->pluck('started_at')
        ->map->format('Y-m-d H:i:s')->all())->toBe([
            '2026-01-01 00:00:00',
            '2026-02-01 00:00:00',
        ]);

    CarbonImmutable::setTestNow();
});

it('invoices the first period without waiting for the clock when billed in advance', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 09:41:00', 'UTC'));

    Queue::fake();

    $organization = Organization::factory()->create(['webhook_url' => null]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'UTC',
        'currency' => 'EUR',
    ]);
    $product = billingScenarioFixedProduct($organization);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'billing_timing' => 'advance',
        'proration' => false,
    ]);
    $catalogPlan = CatalogPlan::factory()->create(['organization_id' => $organization->id]);
    PlanRateCard::factory()->create([
        'organization_id' => $organization->id,
        'catalog_plan_id' => $catalogPlan->id,
        'rate_card_id' => $rateCard->id,
        'units' => 5,
    ]);

    RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'effective_from' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        'rate_model' => 'standard',
        'rate_properties' => ['amount' => '10'],
        'billing_interval_count' => 1,
        'billing_interval_unit' => 'month',
    ]);

    Queue::fake([App\Jobs\BillingSegments\ScheduleJob::class, App\Jobs\BillingSegments\ProcessJob::class]);

    $result = ContractsCreateService::call(
        organization: $organization,
        params: [
            'external_customer_id' => $customer->external_id,
            'external_id' => 'contract-advance',
            'plan_code' => $catalogPlan->code,
            'started_at' => '2026-01-15T00:00:00Z',
        ],
    );

    expect($result->success())->toBeTrue();

    // Rails performs the enqueued jobs inside the spec; run the producer +
    // consumer the same way.
    Queue::assertPushed(App\Jobs\BillingSegments\ScheduleJob::class, function ($job) {
        $job->handle();

        return true;
    });
    Queue::assertPushed(App\Jobs\BillingSegments\ProcessJob::class, function ($job) {
        $job->handle();

        return true;
    });

    $invoice = Invoice::query()->where('customer_id', $customer->id)->sole();

    expect($invoice->status->label())->toBe('finalized')
        ->and($invoice->currency)->toBe('EUR')
        ->and((int) $invoice->total_amount_cents)->toBe(5_000);

    $segment = BillingSegment::query()->where('customer_id', $customer->id)->sole();

    expect($segment->status->value)->toBe('done')
        ->and($segment->invoice_id)->toBe($invoice->id);

    CarbonImmutable::setTestNow();
});

it('moves the clock on at creation, so the next tick does not bill the period again', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 09:41:00', 'UTC'));

    Queue::fake([App\Jobs\BillingSegments\ScheduleJob::class, App\Jobs\BillingSegments\ProcessJob::class]);

    $organization = Organization::factory()->create(['webhook_url' => null]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'UTC',
        'currency' => 'EUR',
    ]);
    $product = billingScenarioFixedProduct($organization);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'billing_timing' => 'advance',
        'proration' => false,
    ]);
    $catalogPlan = CatalogPlan::factory()->create(['organization_id' => $organization->id]);
    PlanRateCard::factory()->create([
        'organization_id' => $organization->id,
        'catalog_plan_id' => $catalogPlan->id,
        'rate_card_id' => $rateCard->id,
        'units' => 5,
    ]);

    RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'effective_from' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        'rate_model' => 'standard',
        'rate_properties' => ['amount' => '10'],
        'billing_interval_count' => 1,
        'billing_interval_unit' => 'month',
    ]);

    ContractsCreateService::call(
        organization: $organization,
        params: [
            'external_customer_id' => $customer->external_id,
            'external_id' => 'contract-advance',
            'plan_code' => $catalogPlan->code,
            'started_at' => '2026-01-15T00:00:00Z',
        ],
    );

    // Rails: perform_enqueued_jobs { create_contract } — run the producer,
    // then the chained consumer.
    Queue::assertPushed(App\Jobs\BillingSegments\ScheduleJob::class, function ($job) {
        $job->handle();

        return true;
    });
    Queue::assertPushed(App\Jobs\BillingSegments\ProcessJob::class, function ($job) {
        $job->handle();

        return true;
    });

    $before = BillingSegment::query()->count();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 09:41:00', 'UTC'));
    (new CreateBillingSegmentsJob)->handle();

    expect(BillingSegment::query()->count())->toBe($before);

    CarbonImmutable::setTestNow();
});

it('invoices nothing yet in arrears, the period having not happened', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 09:41:00', 'UTC'));

    Queue::fake([App\Jobs\BillingSegments\ScheduleJob::class, App\Jobs\BillingSegments\ProcessJob::class]);

    $organization = Organization::factory()->create(['webhook_url' => null]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'UTC',
        'currency' => 'EUR',
    ]);
    $product = billingScenarioFixedProduct($organization);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'billing_timing' => 'arrears',
        'proration' => false,
    ]);
    $catalogPlan = CatalogPlan::factory()->create(['organization_id' => $organization->id]);
    PlanRateCard::factory()->create([
        'organization_id' => $organization->id,
        'catalog_plan_id' => $catalogPlan->id,
        'rate_card_id' => $rateCard->id,
        'units' => 5,
    ]);

    RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'effective_from' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        'rate_model' => 'standard',
        'rate_properties' => ['amount' => '10'],
        'billing_interval_count' => 1,
        'billing_interval_unit' => 'month',
    ]);

    $result = ContractsCreateService::call(
        organization: $organization,
        params: [
            'external_customer_id' => $customer->external_id,
            'external_id' => 'contract-arrears',
            'plan_code' => $catalogPlan->code,
            'started_at' => '2026-01-15T00:00:00Z',
        ],
    );

    expect($result->success())->toBeTrue();

    Queue::assertPushed(App\Jobs\BillingSegments\ScheduleJob::class, function ($job) {
        $job->handle();

        return true;
    });

    expect(Invoice::query()->where('customer_id', $customer->id)->count())->toBe(0)
        ->and(BillingSegment::query()->where('customer_id', $customer->id)->count())->toBe(0);

    CarbonImmutable::setTestNow();
});
