<?php

declare(strict_types=1);

use App\Jobs\BillSubscriptionJob;
use App\Models\InvoiceSubscription;
use App\Jobs\Subscriptions\TerminateJob;
use App\Services\Organizations\BillingService;

/**
 * Port of spec/services/subscriptions/organization_billing_service_spec.rb
 * core scenarios: the billable-today UNION query (calendar + anniversary,
 * already-billed exclusion) and the per-customer grouping chain.
 */
function billerFixture(): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'active',
        'billing_time' => 'calendar',
        'external_id' => 'sub-biller-1',
        'started_at' => '2025-01-01 00:00:00',
        'activated_at' => '2025-01-01 00:00:00',
        'subscription_at' => '2025-01-01 00:00:00',
        'created_at' => '2025-01-01 00:00:00',
    ]);

    return compact('organization', 'customer', 'plan', 'subscription');
}

function billerAt(string $utc): Carbon\CarbonImmutable
{
    return Carbon\CarbonImmutable::parse($utc, 'UTC');
}

it('bills a monthly calendar subscription on the first of the month', function () {
    $f = billerFixture();
    Illuminate\Support\Facades\Bus::fake([BillSubscriptionJob::class]);

    // 2026-10-01 = Thursday, first of the month → monthly calendar fires.
    BillingService::call(organization: $f['organization'], billingAt: billerAt('2026-10-01 12:00:00'));

    Illuminate\Support\Facades\Bus::assertDispatched(BillSubscriptionJob::class, 1);
    Illuminate\Support\Facades\Bus::assertDispatched(function (BillSubscriptionJob $job) {
        return $job->invoicingReason === 'subscription_periodic';
    });
})->group('ledger:svc:Subscriptions.OrganizationBillingService');

it('does not bill the same subscription twice on one day (already_billed_today CTE)', function () {
    $f = billerFixture();
    Illuminate\Support\Facades\Bus::fake([BillSubscriptionJob::class]);

    // A recurring invoice subscription stamped today marks it already billed.
    InvoiceSubscription::query()->create([
        'invoice_id' => App\Models\Invoice::factory()->create(['customer_id' => $f['customer']->id])->id,
        'subscription_id' => $f['subscription']->id,
        'organization_id' => $f['organization']->id,
        'recurring' => true,
        'timestamp' => '2026-10-01 09:00:00',
        'from_datetime' => '2026-10-01 00:00:00',
        'to_datetime' => '2026-10-31 23:59:59.999999',
        'charges_from_datetime' => '2026-10-01 00:00:00',
        'charges_to_datetime' => '2026-10-31 23:59:59.999999',
        'invoicing_reason' => 'subscription_periodic',
    ]);

    BillingService::call(organization: $f['organization'], billingAt: billerAt('2026-10-01 15:00:00'));

    Illuminate\Support\Facades\Bus::assertNotDispatched(BillSubscriptionJob::class);
})->group('ledger:svc:Subscriptions.OrganizationBillingService');

it('does not bill on a non-billing day', function () {
    $f = billerFixture();
    Illuminate\Support\Facades\Bus::fake([BillSubscriptionJob::class]);

    // Mid-month Thursday: no calendar branch fires for a monthly plan.
    BillingService::call(organization: $f['organization'], billingAt: billerAt('2026-10-08 12:00:00'));

    Illuminate\Support\Facades\Bus::assertNotDispatched(BillSubscriptionJob::class);
})->group('ledger:svc:Subscriptions.OrganizationBillingService');

it('bills an anniversary subscription on its monthly anniversary day', function () {
    $f = billerFixture();
    $f['subscription']->update([
        'billing_time' => 'anniversary',
        'subscription_at' => '2025-01-05 00:00:00',
        'started_at' => '2025-01-05 00:00:00',
        'activated_at' => '2025-01-05 00:00:00',
    ]);
    Illuminate\Support\Facades\Bus::fake([BillSubscriptionJob::class]);

    // The 5th of the month is the anniversary day.
    BillingService::call(organization: $f['organization'], billingAt: billerAt('2026-10-05 12:00:00'));

    Illuminate\Support\Facades\Bus::assertDispatched(BillSubscriptionJob::class);
})->group('ledger:svc:Subscriptions.OrganizationBillingService');

it('terminates the current subscription when a downgrade is pending today', function () {
    $f = billerFixture();
    $nextPlan = App\Models\Plan::factory()->create([
        'organization_id' => $f['organization']->id,
        'amount_cents' => 100,
    ]);
    $next = App\Models\Subscription::factory()->create([
        'customer_id' => $f['customer']->id,
        'plan_id' => $nextPlan->id,
        'organization_id' => $f['organization']->id,
        'status' => 'pending',
        'billing_time' => 'calendar',
        'external_id' => 'sub-biller-1',
        'started_at' => '2026-10-01 00:00:00',
        'subscription_at' => '2026-10-01 00:00:00',
        'created_at' => '2025-01-01 00:00:00',
    ]);
    // The downgrade links the NEXT subscription via previous_subscription_id.
    Illuminate\Support\Facades\DB::table('subscriptions')
        ->where('id', $next->id)
        ->update(['previous_subscription_id' => $f['subscription']->id]);

    Illuminate\Support\Facades\Bus::fake([TerminateJob::class, BillSubscriptionJob::class]);

    BillingService::call(organization: $f['organization'], billingAt: billerAt('2026-10-01 12:00:00'));

    Illuminate\Support\Facades\Bus::assertDispatched(TerminateJob::class);
    Illuminate\Support\Facades\Bus::assertNotDispatched(BillSubscriptionJob::class);
})->group('ledger:svc:Subscriptions.OrganizationBillingService');

it('groups subscriptions by currency and consolidation into separate invoices', function () {
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $eurPlan = App\Models\Plan::factory()->create(['organization_id' => $organization->id, 'amount_currency' => 'EUR']);
    $usdPlan = App\Models\Plan::factory()->create(['organization_id' => $organization->id, 'amount_currency' => 'USD']);
    $a = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id, 'plan_id' => $eurPlan->id, 'organization_id' => $organization->id,
        'status' => 'active', 'billing_time' => 'calendar',
        'started_at' => '2025-01-01 00:00:00', 'activated_at' => '2025-01-01 00:00:00',
        'subscription_at' => '2025-01-01 00:00:00', 'created_at' => '2025-01-01 00:00:00',
        'external_id' => 'sub-a',
    ]);
    $b = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id, 'plan_id' => $usdPlan->id, 'organization_id' => $organization->id,
        'status' => 'active', 'billing_time' => 'calendar',
        'started_at' => '2025-01-01 00:00:00', 'activated_at' => '2025-01-01 00:00:00',
        'subscription_at' => '2025-01-01 00:00:00', 'created_at' => '2025-01-01 00:00:00',
        'external_id' => 'sub-b',
    ]);

    Illuminate\Support\Facades\Bus::fake([BillSubscriptionJob::class]);
    BillingService::call(organization: $organization, billingAt: billerAt('2026-10-01 12:00:00'));

    // Two currencies → two BillSubscriptionJob dispatches.
    Illuminate\Support\Facades\Bus::assertDispatched(BillSubscriptionJob::class, 2);

    Illuminate\Support\Facades\Bus::assertDispatched(function (BillSubscriptionJob $job) use ($a) {
        return count($job->subscriptions) === 1 && $job->subscriptions[0]->id === $a->id;
    });
    Illuminate\Support\Facades\Bus::assertDispatched(function (BillSubscriptionJob $job) use ($b) {
        return count($job->subscriptions) === 1 && $job->subscriptions[0]->id === $b->id;
    });
})->group('ledger:svc:Subscriptions.OrganizationBillingService');
