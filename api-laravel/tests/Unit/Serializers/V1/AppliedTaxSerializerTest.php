<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Serializers\V1\Fees\AppliedTaxSerializer as FeeAppliedTaxSerializer;
use App\Serializers\V1\Invoices\AppliedTaxSerializer as InvoiceAppliedTaxSerializer;

/**
 * Ports of spec/serializers/v1/fees/applied_tax_serializer_spec.rb and
 * invoices/applied_tax_serializer_spec.rb — the fee- and invoice-level tax
 * snapshot rows.
 */
it('serializes a fee applied tax with literal snake_case keys', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
    ]);
    $fee = Fee::factory()->subscriptionFee()->create([
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'amount_currency' => 'EUR',
    ]);
    $appliedTax = App\Models\FeeAppliedTax::query()->create([
        'organization_id' => $organization->id,
        'fee_id' => $fee->id,
        'tax_id' => App\Models\Tax::factory()->create(['organization_id' => $organization->id, 'name' => 'VAT', 'code' => 'vat-20', 'rate' => 20.0, 'description' => 'Standard rate'])->id,
        'tax_name' => 'VAT',
        'tax_code' => 'vat-20',
        'tax_rate' => 20.0,
        'tax_description' => 'Standard rate',
        'amount_currency' => 'EUR',
        'amount_cents' => 20,
        'precise_amount_cents' => '20.0000000001',
    ]);

    $payload = (new FeeAppliedTaxSerializer($appliedTax))->serialize();

    expect($payload['lago_id'])->toBe($appliedTax->id)
        ->and($payload['lago_fee_id'])->toBe($fee->id)
        ->and($payload['lago_tax_id'])->toBe($appliedTax->tax_id)
        ->and($payload['tax_name'])->toBe('VAT')
        ->and($payload['tax_code'])->toBe('vat-20')
        ->and($payload['tax_rate'])->toBe(20.0)
        ->and($payload['tax_description'])->toBe('Standard rate')
        ->and($payload['amount_cents'])->toBe(20)
        ->and($payload['amount_currency'])->toBe('EUR')
        // Rails: created_at&.iso8601 (offset form, not the Z form).
        ->and($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
})->group('ledger:ser:V1.Fees.AppliedTaxSerializer');

it('serializes an invoice applied tax with literal snake_case keys', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'currency' => 'EUR',
    ]);
    $appliedTax = App\Models\InvoiceAppliedTax::query()->create([
        'organization_id' => $organization->id,
        'invoice_id' => $invoice->id,
        'tax_id' => App\Models\Tax::factory()->create(['organization_id' => $organization->id, 'name' => 'VAT', 'code' => 'vat-20', 'rate' => 20.0, 'description' => 'Standard rate'])->id,
        'tax_name' => 'VAT',
        'tax_code' => 'vat-20',
        'tax_rate' => 20.0,
        'tax_description' => 'Standard rate',
        'amount_currency' => 'EUR',
        'amount_cents' => 200,
        'precise_amount_cents' => '200',
        'fees_amount_cents' => 1000,
    ]);

    $payload = (new InvoiceAppliedTaxSerializer($appliedTax))->serialize();

    expect($payload['lago_id'])->toBe($appliedTax->id)
        ->and($payload['lago_invoice_id'])->toBe($invoice->id)
        ->and($payload['lago_tax_id'])->toBe($appliedTax->tax_id)
        ->and($payload['tax_name'])->toBe('VAT')
        ->and($payload['tax_code'])->toBe('vat-20')
        ->and($payload['tax_rate'])->toBe(20.0)
        ->and($payload['tax_description'])->toBe('Standard rate')
        ->and($payload['amount_cents'])->toBe(200)
        ->and($payload['amount_currency'])->toBe('EUR')
        ->and($payload['fees_amount_cents'])->toBe(1000)
        ->and($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
})->group('ledger:ser:V1.Invoices.AppliedTaxSerializer');
