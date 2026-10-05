<?php

declare(strict_types=1);

uses()->group('ledger:svc:LifetimeUsages.CalculateService',
    'ledger:svc:LifetimeUsages.UpdateService',
    'ledger:svc:LifetimeUsages.FlagRefreshFromInvoiceService',
    'ledger:svc:LifetimeUsages.FlagRefreshFromPlanUpdateService',
    'ledger:svc:LifetimeUsages.FindLastAndNextThresholdsService',
    'ledger:svc:LifetimeUsages.UsageThresholdsCompletionService',
    'ledger:svc:LifetimeUsages.UsageThresholds.CheckService',
    'ledger:svc:Subscriptions.ProgressiveBilledAmount');

use App\Models\Plan;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\LifetimeUsage;
use App\Models\UsageThreshold;
use App\Services\LifetimeUsages\UpdateService;
use App\Services\LifetimeUsages\CalculateService;
use App\Services\Subscriptions\ProgressiveBilledAmount;
use App\Services\LifetimeUsages\UsageThresholds\CheckService;
use App\Services\LifetimeUsages\FlagRefreshFromInvoiceService;
use App\Services\LifetimeUsages\FindLastAndNextThresholdsService;
use App\Services\LifetimeUsages\FlagRefreshFromPlanUpdateService;
use App\Services\LifetimeUsages\UsageThresholdsCompletionService;

/**
 * Ports of spec/services/lifetime_usages/*_spec.rb and
 * spec/services/subscriptions/progressive_billed_amount_spec.rb (core
 * scenarios). The current-usage computation is fed in directly where Rails
 * exercises it through CustomerUsageService.
 */
function ltuScenario(array $subscriptionAttributes = []): array
{
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = App\Models\Customer::factory()->for($organization)->create();

    $subscription = Subscription::factory()->for($customer)->for($plan)->create(array_merge([
        'organization_id' => $organization->id,
        'external_id' => 'sub-1',
        'status' => 1,
    ], $subscriptionAttributes));

    $lifetimeUsage = new LifetimeUsage([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'current_usage_amount_cents' => 0,
        'invoiced_usage_amount_cents' => 0,
        'historical_usage_amount_cents' => 0,
    ]);
    $lifetimeUsage->save();

    return [$organization, $plan, $subscription, $lifetimeUsage];
}

it('calculates the lifetime usage from the recalculate flags', function (): void {
    [, , , $lifetimeUsage] = ltuScenario();

    $lifetimeUsage->recalculate_current_usage = true;
    $lifetimeUsage->recalculate_invoiced_usage = true;
    $lifetimeUsage->save();

    $result = CalculateService::call(lifetimeUsage: $lifetimeUsage, currentUsage: (object) ['amountCents' => 4321]);

    $lifetimeUsage = $lifetimeUsage->fresh();

    expect($result->success())->toBeTrue()
        ->and($lifetimeUsage->current_usage_amount_cents)->toBe(4321)
        ->and($lifetimeUsage->recalculate_current_usage)->toBeFalse()
        ->and($lifetimeUsage->current_usage_amount_refreshed_at)->not->toBeNull()
        // No invoice fees exist yet.
        ->and($lifetimeUsage->invoiced_usage_amount_cents)->toBe(0)
        ->and($lifetimeUsage->totalAmountCents())->toBe(4321);
});

it('clears the flags without recalculating for a terminated subscription', function (): void {
    [, , , $lifetimeUsage] = ltuScenario(['status' => 2]);

    $lifetimeUsage->update(['recalculate_current_usage' => true, 'recalculate_invoiced_usage' => true]);

    $result = CalculateService::call(lifetimeUsage: $lifetimeUsage, currentUsage: (object) ['amountCents' => 999]);

    $lifetimeUsage = $lifetimeUsage->fresh();

    expect($result->success())->toBeTrue()
        ->and($lifetimeUsage->current_usage_amount_cents)->toBe(0)
        ->and($lifetimeUsage->recalculate_current_usage)->toBeFalse()
        ->and($lifetimeUsage->recalculate_invoiced_usage)->toBeFalse();
});

it('flags the invoiced-usage recalculation from an invoice', function (): void {
    config(['lago.license' => 'premium-license-token']);

    $organization = Organization::factory()->create(['premium_integrations' => ['lifetime_usage']]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = App\Models\Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'external_id' => 'sub-1',
        'status' => 1,
    ]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'invoice_type' => 0,
        'status' => 1,
    ]);

    $subscription->createLifetimeUsage();

    App\Models\InvoiceSubscription::query()->create([
        'organization_id' => $organization->id,
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'timestamp' => now(),
        'from_datetime' => now()->subDays(5),
        'to_datetime' => now()->addDays(25),
        'charges_from_datetime' => now()->subDays(5),
        'charges_to_datetime' => now()->addDays(25),
        'invoicing_reason' => 'subscription_periodic',
    ]);

    $result = FlagRefreshFromInvoiceService::callBang(invoice: $invoice);

    expect($result->lifetime_usages)->toHaveCount(1)
        ->and($subscription->fresh()->lifetimeUsage->recalculate_invoiced_usage)->toBeTrue();
});

it('does not flag the recalculation without the premium features', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = App\Models\Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'status' => 1,
    ]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'invoice_type' => 0,
        'status' => 1,
    ]);

    $result = FlagRefreshFromInvoiceService::call(invoice: $invoice);

    expect($result->lifetime_usages)->toBe([]);
});

it('flags every active subscription of a plan on a plan update', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = App\Models\Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'status' => 1,
    ]);

    $subscription->createLifetimeUsage();

    Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'external_id' => 'sub-terminated',
        'status' => 2,
    ])->createLifetimeUsage();

    $result = FlagRefreshFromPlanUpdateService::callBang(plan: $plan);

    expect($result->updated_lifetime_usages)->toBe(1)
        ->and($subscription->fresh()->lifetimeUsage->recalculate_invoiced_usage)->toBeTrue();
});

it('updates the externally reported historical usage', function (): void {
    [, , , $lifetimeUsage] = ltuScenario();

    $result = UpdateService::call(
        lifetimeUsage: $lifetimeUsage,
        params: ['external_historical_usage_amount_cents' => 777],
    );

    expect($result->success())->toBeTrue()
        ->and($lifetimeUsage->fresh()->historical_usage_amount_cents)->toBe(777)
        ->and($lifetimeUsage->totalAmountCents())->toBe(777);

    $missing = UpdateService::call(lifetimeUsage: null, params: []);

    expect($missing->success())->toBeFalse()
        ->and($missing->getError()->getMessage())->toContain('lifetime_usage');
});

// -- UsageThresholds::CheckService ------------------------------------------------

function checkScenario(int $currentUsage, int $invoicedUsage, int $historicalUsage, array $thresholds): array
{
    [, , $subscription, $lifetimeUsage] = ltuScenario();

    $lifetimeUsage->update([
        'current_usage_amount_cents' => $currentUsage,
        'invoiced_usage_amount_cents' => $invoicedUsage,
        'historical_usage_amount_cents' => $historicalUsage,
    ]);

    foreach ($thresholds as $threshold) {
        UsageThreshold::query()->create($threshold + [
            'organization_id' => $subscription->organization_id,
            'plan_id' => $subscription->plan_id,
        ]);
    }

    return [$subscription, $lifetimeUsage];
}

it('passes fixed thresholds crossed by the total usage', function (): void {
    [$subscription, $lifetimeUsage] = checkScenario(
        currentUsage: 1000,
        invoicedUsage: 0,
        historicalUsage: 100,
        thresholds: [
            ['amount_cents' => 100, 'recurring' => false],
            ['amount_cents' => 1000, 'recurring' => false],
            ['amount_cents' => 5000, 'recurring' => false],
        ],
    );

    $result = CheckService::callBang(lifetimeUsage: $lifetimeUsage);

    $passed = collect($result->passed_thresholds)->pluck('amount_cents')->all();

    // invoiced (100) < largest (5000): keep thresholds in (100, 1100].
    expect($passed)->toBe([1000]);
});

it('passes the recurring threshold by multiples over the largest fixed one', function (): void {
    [$subscription, $lifetimeUsage] = checkScenario(
        currentUsage: 2500,
        invoicedUsage: 0,
        historicalUsage: 0,
        thresholds: [
            ['amount_cents' => 1000, 'recurring' => false],
            ['amount_cents' => 1000, 'recurring' => true],
        ],
    );

    $result = CheckService::callBang(lifetimeUsage: $lifetimeUsage);

    $passed = collect($result->passed_thresholds)->pluck('recurring', 'amount_cents')->all();

    // total_usage 2500 - largest 1000 = 1500 >= 1000.
    expect($passed)->toBe([1000 => true]);
});

it('passes the recurring threshold above the largest fixed one by the remainder rule', function (): void {
    [$subscription, $lifetimeUsage] = checkScenario(
        currentUsage: 600,
        invoicedUsage: 2000,
        historicalUsage: 0,
        thresholds: [
            ['amount_cents' => 1000, 'recurring' => false],
            ['amount_cents' => 500, 'recurring' => true],
        ],
    );

    $result = CheckService::callBang(lifetimeUsage: $lifetimeUsage);

    // invoiced (2000) >= largest (1000): remainder = 2000 % 500 = 0;
    // 600 + 0 >= 500 → passed.
    expect(collect($result->passed_thresholds))->toHaveCount(1)
        ->and($result->passed_thresholds[0]->recurring)->toBeTrue();
});

it('passes nothing when the progressively billed amount exceeds current usage', function (): void {
    [, , $subscription, $lifetimeUsage] = ltuScenario();

    $lifetimeUsage->update(['current_usage_amount_cents' => 100]);

    $result = CheckService::call(lifetimeUsage: $lifetimeUsage, progressiveBilledAmount: 500);

    expect($result->passed_thresholds)->toBe([]);
});

// -- UsageThresholdsCompletionService / FindLastAndNext ---------------------------

it('walks the thresholds with completion ratios', function (): void {
    [$subscription, $lifetimeUsage] = checkScenario(
        currentUsage: 150,
        invoicedUsage: 0,
        historicalUsage: 0,
        thresholds: [
            ['amount_cents' => 100, 'recurring' => false],
            ['amount_cents' => 300, 'recurring' => false],
        ],
    );

    $result = UsageThresholdsCompletionService::callBang(lifetimeUsage: $lifetimeUsage);

    $rows = collect($result->usage_thresholds);

    expect($rows->count())->toBe(2)
        ->and($rows[0]['amount_cents'])->toBe(100)
        ->and($rows[0]['completion_ratio'])->toEqualWithDelta(1.0, 0.0001)
        ->and($rows[0]['reached_at'])->not->toBeNull()
        ->and($rows[1]['amount_cents'])->toBe(300)
        ->and($rows[1]['completion_ratio'])->toEqualWithDelta(0.25, 0.0001)
        ->and($rows[1]['reached_at'])->toBeNull();
});

it('finds the last and next thresholds', function (): void {
    [$subscription, $lifetimeUsage] = checkScenario(
        currentUsage: 150,
        invoicedUsage: 0,
        historicalUsage: 0,
        thresholds: [
            ['amount_cents' => 100, 'recurring' => false],
            ['amount_cents' => 300, 'recurring' => false],
        ],
    );

    $result = FindLastAndNextThresholdsService::callBang(lifetimeUsage: $lifetimeUsage);

    expect($result->last_threshold_amount_cents)->toBe(100)
        ->and($result->next_threshold_amount_cents)->toBe(300)
        ->and($result->next_threshold_ratio)->toEqualWithDelta(0.25, 0.0001);
});

// -- Subscriptions::ProgressiveBilledAmount ---------------------------------------

it('sums the progressively billed amount of the last invoice', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = App\Models\Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'status' => 1,
    ]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'invoice_type' => 5, // progressive_billing
        'status' => 1, // finalized
        'fees_amount_cents' => 700,
        'coupons_amount_cents' => 0,
        'total_amount_cents' => 700,
        'created_at' => now()->subDay(),
    ]);

    $now = now();

    App\Models\InvoiceSubscription::query()->create([
        'organization_id' => $organization->id,
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'timestamp' => $now,
        'from_datetime' => $now->copy()->subDays(5),
        'to_datetime' => $now->copy()->addDays(25),
        'charges_from_datetime' => $now->copy()->subDays(5),
        'charges_to_datetime' => $now->copy()->addDays(25),
        'invoicing_reason' => 'progressive_billing',
    ]);

    $result = ProgressiveBilledAmount::call(subscription: $subscription);

    expect($result->progressive_billed_amount)->toBe(700)
        ->and($result->total_billed_amount_cents)->toBe(0) // no fees yet
        ->and($result->progressive_billing_invoice?->is($invoice))->toBeTrue()
        ->and($result->to_invoice_amount)->toBe(700)
        ->and($result->to_credit_amount)->toBe(700);
});

it('answers zero without progressive billing invoices', function (): void {
    [, , $subscription] = ltuScenario();

    $result = ProgressiveBilledAmount::call(subscription: $subscription);

    expect($result->progressive_billed_amount)->toBe(0)
        ->and($result->progressive_billing_invoice)->toBeNull()
        ->and($result->to_credit_amount)->toBe(0)
        ->and($result->to_invoice_amount)->toBe(0);
});
