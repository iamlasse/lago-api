<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Services\Failures\ValidationFailure;
use App\Services\Invoices\SubscriptionService;

/**
 * Port of spec/services/invoices/subscription_service_spec.rb validation and
 * guard scenarios (the happy-path pipeline runs against the real service in
 * tests/Feature/Jobs/BillSubscriptionJobPipelineTest.php).
 *
 * Not ported from the Rails spec: wallet/lifetime-usage flagging, webhooks,
 * custom sections and the activation-rules payment gating — those seams are
 * TODO(port) in the service.
 */
function subSvcFixture(?App\Models\Organization $organization = null, array $overrides = []): array
{
    $organization ??= App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'interval' => 'monthly',
        'pay_in_advance' => false,
    ]);
    $subscription = App\Models\Subscription::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'active',
        'external_id' => 'sub-svc-1',
        'billing_time' => 'calendar',
        'started_at' => '2025-01-01 00:00:00',
        'activated_at' => '2025-01-01 00:00:00',
        'subscription_at' => '2025-01-01 00:00:00',
    ], $overrides));

    return compact('organization', 'customer', 'plan', 'subscription');
}

function subSvcTimestamp(): int
{
    return Carbon\CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC')->getTimestamp();
}

it('fails with mixed_purchase_order_numbers when subscriptions disagree', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $f = subSvcFixture($organization, ['purchase_order_number' => 'PO-1']);
    $other = subSvcFixture($organization, ['external_id' => 'sub-svc-2', 'purchase_order_number' => 'PO-2']);

    $result = SubscriptionService::call(
        subscriptions: [$f['subscription'], $other['subscription']],
        timestamp: subSvcTimestamp(),
        invoicingReason: 'subscription_periodic',
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe([
            'purchase_order_number' => ['mixed_purchase_order_numbers'],
        ])
        ->and(Invoice::query()->where('customer_id', $f['customer']->id)->count())->toBe(0);
})->group('ledger:svc:Invoices.SubscriptionService');

it('fails with mixed_billing_entities when subscriptions disagree', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $f = subSvcFixture($organization);
    $otherEntity = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $other = subSvcFixture($organization, ['external_id' => 'sub-svc-3']);
    $other['subscription']->update(['billing_entity_id' => $otherEntity->id]);

    $result = SubscriptionService::call(
        subscriptions: [$f['subscription'], $other['subscription']],
        timestamp: subSvcTimestamp(),
        invoicingReason: 'subscription_periodic',
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe([
            'billing_entity' => ['mixed_billing_entities'],
        ]);
})->group('ledger:svc:Invoices.SubscriptionService');

it('succeeds without an invoice for a periodic run with no active subscription', function (): void {
    $f = subSvcFixture(null, ['status' => 'terminated']);

    $result = SubscriptionService::call(
        subscriptions: [$f['subscription']],
        timestamp: subSvcTimestamp(),
        invoicingReason: 'subscription_periodic',
    );

    expect($result->success())->toBeTrue()
        ->and($result->invoice)->toBeNull()
        ->and(Invoice::query()->where('customer_id', $f['customer']->id)->count())->toBe(0);
})->group('ledger:svc:Invoices.SubscriptionService');

it('runs activation billing with locked subscriptions and skips charges', function (): void {
    $f = subSvcFixture();

    $result = SubscriptionService::call(
        subscriptions: [$f['subscription']],
        timestamp: subSvcTimestamp(),
        invoicingReason: 'subscription_starting',
        skipCharges: true,
    );

    $invoice = $result->invoice;

    expect($result->success())->toBeTrue()
        ->and($invoice)->not->toBeNull()
        ->and((bool) $invoice->skip_charges)->toBeTrue()
        // skip_charges drops the charge fees only — the subscription fee for
        // the pay-in-arrears plan still bills.
        ->and($invoice->fees)->toHaveCount(1)
        ->and((int) $invoice->fees[0]->amount_cents)->toBe(1000)
        ->and($invoice->statusEnum()->label())->toBe('finalized')
        // Boundary rows recorded for the starting subscription.
        ->and($invoice->invoiceSubscriptions)->toHaveCount(1)
        ->and($invoice->invoiceSubscriptions[0]->invoicing_reason)->toBe('subscription_starting')
        ->and($invoice->invoiceSubscriptions[0]->recurring)->toBeFalse();
})->group('ledger:svc:Invoices.SubscriptionService');
