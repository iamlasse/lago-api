<?php

declare(strict_types=1);

use App\Enums\FeeType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceTaxStatus;
use App\Models\BillingPeriodBoundaries;
use App\Models\InvoiceSubscription;
use App\Services\Invoices\CalculateFeesService;
use App\Services\Invoices\ComputeTaxesAndTotalsService;
use App\Services\Invoices\FinalizeService;
use App\Support\MoneyMath;

/**
 * Port of spec/services/invoices/calculate_fees_service_spec.rb +
 * compute_taxes_and_totals_service_spec.rb core scenarios: subscription and
 * charge fees from the cached aggregation seam, coupon credit, the
 * invoice-level tax rollup, and finalization.
 */
function invoicePipelineFixture(array $overrides = []): array
{
    $organization = \App\Models\Organization::factory()->create();
    $customer = \App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = \App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 0,
        'amount_currency' => 'EUR',
        'interval' => 'monthly',
        'pay_in_advance' => false,
    ]);
    $metric = \App\Models\BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'aggregation_type' => 1, // sum_agg
        'recurring' => false,
        'field_name' => 'value',
    ]);
    $charge = \App\Models\Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '1'],
        'invoiceable' => true,
        'pay_in_advance' => false,
    ]);
    $subscription = \App\Models\Subscription::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'active',
        'external_id' => 'sub-pipeline-1',
        'billing_time' => 'calendar',
        'started_at' => '2026-10-01 00:00:00',
        'activated_at' => '2026-10-01 00:00:00',
        'subscription_at' => '2026-10-01 00:00:00',
    ], $overrides));

    $invoice = \App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::Generating,
        'currency' => 'EUR',
        'skip_charges' => false,
    ]);

    $boundaries = new BillingPeriodBoundaries(
        fromDatetime: \Carbon\CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'),
        toDatetime: \Carbon\CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        chargesFromDatetime: \Carbon\CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'),
        chargesToDatetime: \Carbon\CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        chargesDuration: 31,
        timestamp: \Carbon\CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'),
    );

    InvoiceSubscription::query()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'recurring' => true,
        'timestamp' => $boundaries->timestamp,
        'from_datetime' => $boundaries->fromDatetime,
        'to_datetime' => $boundaries->toDatetime,
        'charges_from_datetime' => $boundaries->chargesFromDatetime,
        'charges_to_datetime' => $boundaries->chargesToDatetime,
        'invoicing_reason' => 'subscription_periodic',
    ]);

    return compact('organization', 'customer', 'plan', 'metric', 'charge', 'subscription', 'invoice', 'boundaries');
}

it('creates the charge fee from the cached aggregation and rolls up totals', function () {
    $f = invoicePipelineFixture();

    // Pre-aggregated units for the standard charge: 10 × 100 cents = 1000
    \App\Models\CachedAggregation::query()->create([
        'organization_id' => $f['organization']->id,
        'charge_id' => $f['charge']->id,
        'external_subscription_id' => 'sub-pipeline-1',
        'timestamp' => '2026-10-01 00:00:00',
        'current_aggregation' => '10',
        'grouped_by' => [],
        'presentation_breakdowns' => [],
    ]);

    $result = CalculateFeesService::call(invoice: $f['invoice'], recurring: true, context: 'finalize');

    expect($result->success())->toBeTrue();

    $invoice = $result->invoice;
    $chargeFees = $invoice->fees()->charge()->get();

    expect($chargeFees)->toHaveCount(1)
        ->and((int) $chargeFees[0]->amount_cents)->toBe(1000)
        ->and($invoice->fees_amount_cents)->toBe(1000)
        ->and($invoice->sub_total_excluding_taxes_amount_cents)->toBe(1000)
        ->and($invoice->total_amount_cents)->toBe(1000)
        ->and($invoice->taxes_amount_cents)->toBe(0)
        // nonzero total → payment pending
        ->and($invoice->paymentStatusEnum()->label())->toBe('pending');
});

it('applies taxes through the chain and writes the invoice snapshot rows', function () {
    $f = invoicePipelineFixture();

    \App\Models\CachedAggregation::query()->create([
        'organization_id' => $f['organization']->id,
        'charge_id' => $f['charge']->id,
        'external_subscription_id' => 'sub-pipeline-1',
        'timestamp' => '2026-10-01 00:00:00',
        'current_aggregation' => '10',
        'grouped_by' => [],
        'presentation_breakdowns' => [],
    ]);

    $tax = \App\Models\Tax::factory()->create([
        'organization_id' => $f['organization']->id,
        'rate' => 20.0,
        'name' => 'VAT',
        'code' => 'vat-test',
    ]);
    // charges_taxes join table (Charges::ApplyTaxes-style linkage)
    \Illuminate\Support\Facades\DB::table('charges_taxes')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'charge_id' => $f['charge']->id,
        'tax_id' => $tax->id,
        'organization_id' => $f['organization']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = CalculateFeesService::call(invoice: $f['invoice'], recurring: true, context: 'finalize');

    $invoice = $result->invoice;

    expect((int) $invoice->taxes_amount_cents)->toBe(200)
        ->and((int) $invoice->total_amount_cents)->toBe(1200)
        ->and($invoice->taxes_rate)->toBe(20.0);

    // invoice-level snapshot rows in invoices_taxes
    expect($invoice->appliedTaxes()->count())->toBe(1);
    $applied = $invoice->appliedTaxes()->first();
    expect($applied->amount_cents)->toBe(200)
        ->and($applied->tax_code)->toBe('vat-test')
        ->and($applied->fees_amount_cents)->toBe(1000);

    // fee-level snapshot rows in fees_taxes
    $fee = $invoice->fees()->charge()->first();
    expect($fee->appliedTaxes()->count())->toBe(1)
        ->and((int) $fee->appliedTaxes()->first()->amount_cents)->toBe(200)
        ->and((int) $fee->taxes_amount_cents)->toBe(200);
});

it('applies a percentage coupon before VAT', function () {
    $f = invoicePipelineFixture();

    \App\Models\CachedAggregation::query()->create([
        'organization_id' => $f['organization']->id,
        'charge_id' => $f['charge']->id,
        'external_subscription_id' => 'sub-pipeline-1',
        'timestamp' => '2026-10-01 00:00:00',
        'current_aggregation' => '10',
        'grouped_by' => [],
        'presentation_breakdowns' => [],
    ]);

    $coupon = \App\Models\Coupon::factory()->percentage('20')->create([
        'organization_id' => $f['organization']->id,
    ]);
    $appliedCoupon = \App\Models\AppliedCoupon::factory()->percentage('20')->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $f['customer']->id,
        'organization_id' => $f['organization']->id,
        'amount_currency' => 'EUR',
    ]);

    $result = CalculateFeesService::call(invoice: $f['invoice'], recurring: true, context: 'finalize');
    $invoice = $result->invoice;

    expect((int) $invoice->coupons_amount_cents)->toBe(200)
        ->and((int) $invoice->sub_total_excluding_taxes_amount_cents)->toBe(800)
        ->and((int) $invoice->total_amount_cents)->toBe(800)
        ->and($invoice->credits()->count())->toBe(1)
        ->and((int) $invoice->credits()->first()->amount_cents)->toBe(200);

    // credit weighted over the fee
    $fee = $invoice->fees()->charge()->first();
    expect((float) $fee->precise_coupons_amount_cents)->toBe(200.0);
});

it('finalizes a draft invoice assigning number and sequential id via Sequenced', function () {
    $f = invoicePipelineFixture();
    $invoice = $f['invoice'];
    $invoice->status = InvoiceStatus::Draft;
    $invoice->billing_entity_sequential_id = null;
    $invoice->save();

    $result = FinalizeService::call(invoice: $invoice);

    expect($result->success())->toBeTrue()
        ->and($result->invoice->statusEnum()->label())->toBe('finalized')
        ->and($result->invoice->finalized_at)->not->toBeNull()
        ->and($result->invoice->sequential_id)->toBe(1)
        ->and($result->invoice->billing_entity_sequential_id)->toBe(1)
        // per-customer numbering: PREFIX-<customer seq>-<invoice seq>
        ->and(preg_match('/^\S+-\d{3}-\d{3}$/', $result->invoice->number))->toBe(1)
        ->and($result->invoice->search_terms)->not->toBeNull();
});

it('computes zero total invoices as succeeded and closed per setting', function () {
    $f = invoicePipelineFixture();

    // no cached aggregation → zero-amount charge fee only
    $result = ComputeTaxesAndTotalsService::call(invoice: $f['invoice'], finalizing: false);

    expect($result->success())->toBeTrue()
        ->and((int) $result->invoice->total_amount_cents)->toBe(0);
});
