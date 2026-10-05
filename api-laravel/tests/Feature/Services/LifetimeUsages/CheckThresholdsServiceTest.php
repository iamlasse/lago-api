<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Invoice;
use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\LifetimeUsage;
use App\Models\UsageThreshold;
use App\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\Queue;
use App\Services\LifetimeUsages\CheckThresholdsService;

uses()->group(
    'ledger:svc:LifetimeUsages.CheckThresholdsService',
    'ledger:svc:Invoices.ProgressiveBillingService',
    'ledger:svc:Webhooks.Subscriptions.UsageThresholdsReachedService',
);

/**
 * Ports of Rails' spec/services/lifetime_usages/check_thresholds_service_spec.rb
 * — on a passed threshold the service generates the progressive-billing
 * invoice (Invoices::ProgressiveBillingService) and sends one
 * subscription.usage_threshold_reached webhook per passed threshold
 * (Webhooks::Subscriptions::UsageThresholdsReachedService).
 */
function thresholdServiceOrganization(): Organization
{
    return Organization::factory()->create(['premium_integrations' => ['progressive_billing']]);
}

/**
 * @return array{0: Subscription, 1: LifetimeUsage}
 */
function thresholdServiceScenario(int $currentUsageCents, array $thresholds): array
{
    $organization = thresholdServiceOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = App\Models\Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'status' => 1,
    ]);

    foreach ($thresholds as $threshold) {
        UsageThreshold::query()->create($threshold + [
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
        ]);
    }

    $lifetimeUsage = new LifetimeUsage([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'current_usage_amount_cents' => $currentUsageCents,
        'invoiced_usage_amount_cents' => 0,
        'historical_usage_amount_cents' => 0,
        'recalculate_current_usage' => true,
        'recalculate_invoiced_usage' => true,
    ]);
    $lifetimeUsage->save();

    return [$subscription, $lifetimeUsage];
}

function thresholdWebhooks(): Illuminate\Support\Collection
{
    return collect(Queue::pushedJobs()[SendWebhookJob::class] ?? [])
        ->filter(fn (array $pushed): bool => $pushed['job']->webhookType === 'subscription.usage_threshold_reached')
        ->values();
}

it('sends a webhook and creates an invoice when a threshold is passed', function (): void {
    Queue::fake();

    [$subscription, $lifetimeUsage] = thresholdServiceScenario(20, [
        ['amount_cents' => 10, 'recurring' => false],
    ]);

    $result = CheckThresholdsService::callBang(lifetimeUsage: $lifetimeUsage);

    expect($result->success())->toBeTrue();

    // The flags are left for the recalculation pipeline (Rails: only
    // CalculateService clears them).
    expect($lifetimeUsage->fresh()->recalculate_current_usage)->toBeTrue()
        ->and($lifetimeUsage->fresh()->recalculate_invoiced_usage)->toBeTrue();

    $webhooks = thresholdWebhooks();
    expect($webhooks)->toHaveCount(1)
        ->and($webhooks[0]['job']->options['usage_threshold']->amount_cents)->toBe(10);

    $invoice = Invoice::query()
        ->where('customer_id', $subscription->customer_id)
        ->where('invoice_type', InvoiceType::ProgressiveBilling->value)
        ->latest('id')
        ->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::Finalized);
});

it('sends one webhook per passed threshold and a single invoice', function (): void {
    Queue::fake();

    [$subscription, $lifetimeUsage] = thresholdServiceScenario(401, [
        ['amount_cents' => 10, 'recurring' => false],
        ['amount_cents' => 400, 'recurring' => false],
    ]);

    $result = CheckThresholdsService::callBang(lifetimeUsage: $lifetimeUsage);

    expect($result->success())->toBeTrue();

    $webhookThresholds = thresholdWebhooks()
        ->map(fn (array $pushed): int => (int) $pushed['job']->options['usage_threshold']->amount_cents)
        ->sort()->values()->all();

    expect($webhookThresholds)->toBe([10, 400])
        ->and(Invoice::query()->where('customer_id', $subscription->customer_id)->count())->toBe(1);
});

it('sends nothing when no threshold is passed', function (): void {
    Queue::fake();

    [, $lifetimeUsage] = thresholdServiceScenario(0, [
        ['amount_cents' => 3000, 'recurring' => false],
    ]);

    $result = CheckThresholdsService::callBang(lifetimeUsage: $lifetimeUsage);

    expect($result->success())->toBeTrue()
        ->and(thresholdWebhooks())->toHaveCount(0)
        ->and(Invoice::query()->where('invoice_type', InvoiceType::ProgressiveBilling->value)->count())->toBe(0);
});

it('does not bill a terminated subscription', function (): void {
    Queue::fake();

    $organization = thresholdServiceOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = App\Models\Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'status' => SubscriptionStatus::Terminated->value,
    ]);
    UsageThreshold::query()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'amount_cents' => 10,
        'recurring' => false,
    ]);
    $lifetimeUsage = new LifetimeUsage([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'current_usage_amount_cents' => 20,
        'invoiced_usage_amount_cents' => 0,
        'historical_usage_amount_cents' => 0,
    ]);
    $lifetimeUsage->save();

    $result = CheckThresholdsService::callBang(lifetimeUsage: $lifetimeUsage);

    expect($result->success())->toBeTrue()
        ->and(thresholdWebhooks())->toHaveCount(0)
        ->and(Invoice::query()->where('invoice_type', InvoiceType::ProgressiveBilling->value)->count())->toBe(0);
});
