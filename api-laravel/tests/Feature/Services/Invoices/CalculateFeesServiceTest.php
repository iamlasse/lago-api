<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\InvoiceSubscription;
use App\Models\BillingPeriodBoundaries;
use App\Services\Invoices\FinalizeService;
use App\Services\Invoices\CalculateFeesService;
use App\Services\Invoices\ComputeTaxesAndTotalsService;

/**
 * Port of spec/services/invoices/calculate_fees_service_spec.rb +
 * compute_taxes_and_totals_service_spec.rb core scenarios: subscription and
 * charge fees from the live event aggregation (finding 12), coupon credit,
 * the invoice-level tax rollup, and finalization.
 */
function invoicePipelineFixture(array $overrides = []): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 0,
        'amount_currency' => 'EUR',
        'interval' => 'monthly',
        'pay_in_advance' => false,
    ]);
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
    $subscription = App\Models\Subscription::factory()->create(array_merge([
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

    $invoice = App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::Generating,
        'currency' => 'EUR',
        'skip_charges' => false,
    ]);

    $boundaries = new BillingPeriodBoundaries(
        fromDatetime: Carbon\CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'),
        toDatetime: Carbon\CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        chargesFromDatetime: Carbon\CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'),
        chargesToDatetime: Carbon\CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        chargesDuration: 31,
        timestamp: Carbon\CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'),
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

/**
 * Seeds the metered input for the pipeline fixture: events inside the
 * billed October period summing `$values` over the metric's field name.
 * Since finding 12 closed, the fee engine aggregates the events LIVE —
 * cached_aggregations rows are ignored on the arrears periodic path.
 */
function invoicePipelineEvents(array $f, array $values): void
{
    foreach ($values as $index => $value) {
        App\Models\Event::factory()->create([
            'organization_id' => $f['organization']->id,
            'external_subscription_id' => $f['subscription']->external_id,
            'transaction_id' => 'tr-pipeline-'.($index + 1),
            'code' => $f['metric']->code,
            'timestamp' => '2026-10-'.mb_str_pad((string) ($index + 2), 2, '0', STR_PAD_LEFT).' 00:00:00',
            'properties' => ['value' => $value],
        ]);
    }
}

it('creates the charge fee from the live event aggregation and rolls up totals', function (): void {
    $f = invoicePipelineFixture();

    // Metered units for the standard charge: 10 × 100 cents = 1000
    invoicePipelineEvents($f, [4, 6]);

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

it('applies taxes through the chain and writes the invoice snapshot rows', function (): void {
    $f = invoicePipelineFixture();

    invoicePipelineEvents($f, [4, 6]);

    $tax = App\Models\Tax::factory()->create([
        'organization_id' => $f['organization']->id,
        'rate' => 20.0,
        'name' => 'VAT',
        'code' => 'vat-test',
    ]);
    // charges_taxes join table (Charges::ApplyTaxes-style linkage)
    Illuminate\Support\Facades\DB::table('charges_taxes')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
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

it('applies a percentage coupon before VAT', function (): void {
    $f = invoicePipelineFixture();

    invoicePipelineEvents($f, [4, 6]);

    $coupon = App\Models\Coupon::factory()->percentage('20')->create([
        'organization_id' => $f['organization']->id,
    ]);
    $appliedCoupon = App\Models\AppliedCoupon::factory()->percentage('20')->create([
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

it('finalizes a draft invoice assigning number and sequential id via Sequenced', function (): void {
    $f = invoicePipelineFixture();
    $invoice = $f['invoice'];
    $invoice->status = InvoiceStatus::Draft;
    $invoice->billing_entity_sequential_id = null;
    $invoice->save();

    $result = FinalizeService::call(invoice: $invoice);
    $result->invoice->refresh();

    expect($result->success())->toBeTrue()
        ->and($result->invoice->statusEnum()->label())->toBe('finalized')
        ->and($result->invoice->finalized_at)->not->toBeNull()
        ->and($result->invoice->sequential_id)->toBe(1)
        ->and($result->invoice->billing_entity_sequential_id)->toBe(1)
        // per-customer numbering: PREFIX-<customer seq>-<invoice seq>
        ->and(preg_match('/^\S+-\d{3}-\d{3}$/', $result->invoice->number))->toBe(1)
        ->and($result->invoice->search_terms)->not->toBeNull();
});

it('computes zero total invoices as succeeded and closed per setting', function (): void {
    $f = invoicePipelineFixture();

    // no cached aggregation → zero-amount charge fee only
    $result = ComputeTaxesAndTotalsService::call(invoice: $f['invoice'], finalizing: false);

    expect($result->success())->toBeTrue()
        ->and((int) $result->invoice->total_amount_cents)->toBe(0);
});
