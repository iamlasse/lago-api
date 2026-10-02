<?php

declare(strict_types=1);

use App\Models\Tax;
use App\Models\Invoice;
use App\Models\Customer;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Support\CurrentContext;
use Database\Factories\TaxFactory;
use Illuminate\Support\Facades\DB;
use App\Services\Taxes\DestroyService;

beforeEach(function () {
    CurrentContext::reset();
});

function destroyTaxFixture(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $tax = TaxFactory::new()->for($organization)->appliedToBillingEntity()->create();
    $customer = Customer::factory()->for($organization)->create();

    $customer->appliedTaxes()->create([
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
    ]);

    return [$organization, $tax, $customer];
}

function invoiceForTaxScenarios(object $organization, Customer $customer, int $status): string
{
    $invoiceId = (string) Str::uuid();
    DB::table('invoices')->insert([
        'id' => $invoiceId,
        'organization_id' => $organization->id,
        'billing_entity_id' => $organization->defaultBillingEntity->id,
        'customer_id' => $customer->id,
        'status' => $status,
        'issuing_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $invoiceId;
}

function insertFee(string $invoiceId, object $organization): string
{
    $feeId = (string) Str::uuid();
    DB::table('fees')->insert([
        'id' => $feeId,
        'invoice_id' => $invoiceId,
        'organization_id' => $organization->id,
        'billing_entity_id' => $organization->defaultBillingEntity->id,
        'amount_cents' => 100,
        'amount_currency' => 'USD',
        'taxes_amount_cents' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $feeId;
}

function insertInvoiceTax(string $invoiceId, object $tax, object $organization): string
{
    $id = (string) Str::uuid();
    DB::table('invoices_taxes')->insert([
        'id' => $id,
        'invoice_id' => $invoiceId,
        'tax_id' => $tax->id,
        'tax_code' => $tax->code,
        'tax_name' => $tax->name,
        'amount_currency' => 'USD',
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function insertFeeTax(string $feeId, object $tax, object $organization): string
{
    $id = (string) Str::uuid();
    DB::table('fees_taxes')->insert([
        'id' => $id,
        'fee_id' => $feeId,
        'tax_id' => $tax->id,
        'tax_code' => $tax->code,
        'tax_name' => $tax->name,
        'amount_cents' => 0,
        'amount_currency' => 'USD',
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('soft deletes the tax', function () {
    [, $tax] = destroyTaxFixture();

    $result = DestroyService::call(tax: $tax);

    expect($result->success())->toBeTrue()
        ->and(Tax::count())->toBe(0)
        ->and($tax->fresh()->trashed())->toBeTrue();
})->group('ledger:svc:Taxes.DestroyService');

it('hard-deletes the customers_taxes join rows', function () {
    [, $tax, $customer] = destroyTaxFixture();

    DestroyService::call(tax: $tax);

    expect(DB::table('customers_taxes')->where('tax_id', $tax->id)->count())->toBe(0)
        ->and($customer->appliedTaxes()->count())->toBe(0);
});

it('removes the draft invoice and fee taxes but keeps the finalized ones', function () {
    [$organization, $tax, $customer] = destroyTaxFixture();

    $draftInvoiceId = invoiceForTaxScenarios($organization, $customer, 0);
    $draftFeeId = insertFee($draftInvoiceId, $organization);
    $draftInvoiceTaxId = insertInvoiceTax($draftInvoiceId, $tax, $organization);
    $draftFeeTaxId = insertFeeTax($draftFeeId, $tax, $organization);

    $finalizedInvoiceId = invoiceForTaxScenarios($organization, $customer, 1);
    $finalizedFeeId = insertFee($finalizedInvoiceId, $organization);
    $finalizedInvoiceTaxId = insertInvoiceTax($finalizedInvoiceId, $tax, $organization);
    $finalizedFeeTaxId = insertFeeTax($finalizedFeeId, $tax, $organization);

    DestroyService::call(tax: $tax);

    expect(DB::table('invoices_taxes')->where('id', $draftInvoiceTaxId)->exists())->toBeFalse()
        ->and(DB::table('fees_taxes')->where('id', $draftFeeTaxId)->exists())->toBeFalse()
        ->and(DB::table('invoices_taxes')->where('id', $finalizedInvoiceTaxId)->exists())->toBeTrue()
        ->and(DB::table('fees_taxes')->where('id', $finalizedFeeTaxId)->exists())->toBeTrue();
});

it('marks the draft invoices of the applicable customers as ready to be refreshed', function () {
    [$organization, $tax, $customer] = destroyTaxFixture();

    $invoiceId = invoiceForTaxScenarios($organization, $customer, 0);

    DestroyService::call(tax: $tax);

    expect(Invoice::query()->find($invoiceId)->ready_to_be_refreshed)->toBeTrue();
});

it('returns the tax untouched when it is already discarded', function () {
    [, $tax, $customer] = destroyTaxFixture();
    $tax->deleted_at = now();
    $tax->save();

    $result = DestroyService::call(tax: $tax);

    expect($result->success())->toBeTrue()
        ->and($result->tax->id)->toBe($tax->id)
        ->and(DB::table('customers_taxes')->where('tax_id', $tax->id)->count())->toBe(1)
        ->and($customer->appliedTaxes()->count())->toBe(1);
});

it('fails when the tax is not found', function () {
    $result = DestroyService::call(tax: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('tax_not_found');
});

// TODO(port): the BillingEntities::Taxes::RemoveTaxesService scenarios from the
// Rails spec (billing_entities_taxes rows removed per billing entity) are
// covered by the TODO(port) hook point in the service.
