<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\RateCard;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Models\RateCardRate;
use App\Models\BillingSegment;
use App\Models\ContractRateCard;
use Illuminate\Support\Facades\Queue;
use App\Jobs\BillingSegments\ProcessJob;
use App\Jobs\BillingSegments\ScheduleJob;
use App\Jobs\Clock\CreateBillingSegmentsJob;
use App\Jobs\Clock\ProcessBillingSegmentsJob;

/**
 * Port of spec/jobs/clock/{create,process}_billing_segments_job_spec.rb +
 * spec/jobs/billing_segments/{schedule,process}_job_spec.rb.
 */
function clockSegmentFixture(bool $due = true): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $product = Product::factory()->fixed()->create(['organization_id' => $organization->id]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'billing_timing' => 'arrears',
    ]);
    $contract = Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => 'active',
        'started_at' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
    ]);
    RateCardRate::factory()->create([
        'organization_id' => $organization->id,
        'rate_card_id' => $rateCard->id,
        'effective_from' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        'billing_interval_count' => 1,
        'billing_interval_unit' => 'month',
    ]);
    $card = ContractRateCard::factory()->create([
        'organization_id' => $organization->id,
        'contract_id' => $contract->id,
        'rate_card_id' => $rateCard->id,
        'effective_date' => '2026-01-01',
        'billing_anchor_date' => '2026-01-01',
        'next_billing_at' => $due
            ? CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC')
            : CarbonImmutable::parse('2099-01-01 00:00:00', 'UTC'),
    ]);

    return compact('organization', 'customer', 'card');
}

function clockStoredSegment(array $fixture, string $status = 'pending'): BillingSegment
{
    return BillingSegment::factory()->create([
        'organization_id' => $fixture['organization']->id,
        'customer_id' => $fixture['customer']->id,
        'contract_id' => $fixture['card']->contract_id,
        'contract_rate_card_id' => $fixture['card']->id,
        'rate_card_rate_id' => RateCardRate::query()->where('rate_card_id', $fixture['card']->rate_card_id)->first()->id,
        'currency' => 'EUR',
        'status' => $status,
        'billing_at' => CarbonImmutable::parse('2026-01-31 23:59:59', 'UTC'),
        'cycle_started_at' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        'started_at' => CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        'ended_at' => BillingSegment::inclusiveEnd(CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC')),
    ]);
}

it('create clock fans one schedule job out per customer with a due card', function (): void {
    $due = clockSegmentFixture(due: true);
    $notDue = clockSegmentFixture(due: false);

    (new CreateBillingSegmentsJob)->handle();

    Queue::assertPushed(ScheduleJob::class, 1);
    Queue::assertPushed(fn (ScheduleJob $job): bool => $job->customerId === $due['customer']->id);
});

it('process clock fans one process job out per customer with awaiting segments', function (): void {
    $fixture = clockSegmentFixture();
    clockStoredSegment($fixture, 'pending');

    // A discarded customer's awaiting segments are not offered.
    $discarded = clockSegmentFixture();
    $discardedSegment = clockStoredSegment($discarded, 'pending');
    $discarded['customer']->delete();

    (new ProcessBillingSegmentsJob)->handle();

    Queue::assertPushed(ProcessJob::class, 1);
    Queue::assertPushed(fn (ProcessJob $job): bool => $job->customerId === $fixture['customer']->id);
});

it('schedule job chains the process job when segments await invoicing', function (): void {
    $fixture = clockSegmentFixture();
    clockStoredSegment($fixture, 'pending');

    (new ScheduleJob($fixture['customer']->id))->handle();

    Queue::assertPushed(ProcessJob::class, 1);
    Queue::assertPushed(fn (ProcessJob $job): bool => $job->customerId === $fixture['customer']->id);
});

it('schedule job chains nothing when no segments await invoicing', function (): void {
    $fixture = clockSegmentFixture();

    // Unpriced card: the producer writes nothing, so there is nothing to chain.
    RateCardRate::query()->where('rate_card_id', $fixture['card']->rate_card_id)->delete();

    (new ScheduleJob($fixture['customer']->id))->handle();

    Queue::assertNotPushed(ProcessJob::class);

    // And the unpriced card's clock stays alone.
    expect(CarbonImmutable::parse($fixture['card']->fresh()->next_billing_at)
        ->equalTo(CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC')))->toBeTrue();
});

it('carries the 30 minutes unique lock ttl matching the clock entries', function (): void {
    expect((new CreateBillingSegmentsJob)->uniqueFor())->toBe(30 * 60)
        ->and((new ProcessBillingSegmentsJob)->uniqueFor())->toBe(30 * 60)
        ->and((new ScheduleJob('x'))->uniqueFor())->toBe(30 * 60)
        ->and((new ProcessJob('x'))->uniqueFor())->toBe(30 * 60);
});
