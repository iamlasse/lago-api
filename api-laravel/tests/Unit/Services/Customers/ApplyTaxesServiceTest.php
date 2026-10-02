<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Support\CurrentContext;
use Database\Factories\TaxFactory;
use Illuminate\Support\Facades\DB;
use App\Services\Customers\ApplyTaxesService;

beforeEach(function (): void {
    CurrentContext::reset();
});

function applyTaxesContext(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();

    $tax1 = TaxFactory::new()->create(['organization_id' => $organization->id, 'code' => 'tax1']);
    $tax2 = TaxFactory::new()->create(['organization_id' => $organization->id, 'code' => 'tax2']);

    return [$organization, $customer, $tax1, $tax2];
}

it('applies taxes to the customer', function (): void {
    [, $customer, $tax1, $tax2] = applyTaxesContext();

    $result = ApplyTaxesService::call(customer: $customer, taxCodes: [$tax1->code, $tax2->code]);

    expect($result->success())->toBeTrue()
        ->and($customer->appliedTaxes()->count())->toBe(2)
        ->and($result->applied_taxes)->toHaveCount(2);
})->group('ledger:svc:Customers.ApplyTaxesService');

it('marks draft invoices as ready to be refreshed', function (): void {
    [, $customer, $tax1, $tax2] = applyTaxesContext();

    // Rails invoice STATUS map: draft = 0, finalized = 1.
    DB::table('invoices')->insert([
        'id' => Illuminate\Support\Str::uuid(),
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'status' => 0, // draft
        'issuing_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('invoices')->insert([
        'id' => Illuminate\Support\Str::uuid(),
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'billing_entity_id' => $customer->billing_entity_id,
        'status' => 1, // finalized
        'issuing_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ApplyTaxesService::call(customer: $customer, taxCodes: [$tax1->code, $tax2->code]);

    // Rails: customer.invoices.draft.update_all(ready_to_be_refreshed: true) —
    // only the draft invoice is flagged.
    expect((bool) DB::table('invoices')->where('customer_id', $customer->id)->where('status', 0)->value('ready_to_be_refreshed'))->toBeTrue()
        ->and((bool) DB::table('invoices')->where('customer_id', $customer->id)->where('status', 1)->value('ready_to_be_refreshed'))->toBeFalse();
});

it('fails when the customer is missing', function (): void {
    $result = ApplyTaxesService::call(customer: null, taxCodes: []);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->getMessage())->toBe('customer_not_found');
});

it('fails when a tax code is unknown', function (): void {
    [, $customer] = applyTaxesContext();

    $result = ApplyTaxesService::call(customer: $customer, taxCodes: ['unknown']);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->getMessage())->toBe('tax_not_found');
});

it('does not duplicate an already applied tax', function (): void {
    [, $customer, $tax1, $tax2] = applyTaxesContext();

    $customer->appliedTaxes()->create([
        'tax_id' => $tax1->id,
        'organization_id' => $customer->organization_id,
    ]);

    expect($customer->appliedTaxes()->count())->toBe(1);

    ApplyTaxesService::call(customer: $customer, taxCodes: [$tax1->code, $tax2->code]);

    expect($customer->appliedTaxes()->count())->toBe(2);
});

it('removes applied taxes that are no longer requested', function (): void {
    [, $customer, $tax1, $tax2] = applyTaxesContext();

    $customer->appliedTaxes()->create([
        'tax_id' => $tax1->id,
        'organization_id' => $customer->organization_id,
    ]);

    ApplyTaxesService::call(customer: $customer, taxCodes: [$tax2->code]);

    expect($customer->appliedTaxes()->count())->toBe(1)
        ->and($customer->appliedTaxes()->first()->tax_id)->toBe($tax2->id);
});

it('assigns a duplicated tax code only once', function (): void {
    [, $customer, $tax1] = applyTaxesContext();

    ApplyTaxesService::call(customer: $customer, taxCodes: [$tax1->code, $tax1->code]);

    expect($customer->appliedTaxes()->count())->toBe(1);
});
