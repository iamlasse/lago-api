<?php

declare(strict_types=1);

use App\Models\Tax;
use App\Models\Customer;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Support\CurrentContext;
use Database\Factories\TaxFactory;
use Illuminate\Support\Facades\DB;
use App\Services\Taxes\UpdateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

beforeEach(function (): void {
    CurrentContext::reset();
});

function updateTaxFixture(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $tax = TaxFactory::new()->for($organization)->create();

    return [$organization, $tax];
}

function draftInvoiceForCustomer(object $organization, Customer $customer): string
{
    $invoiceId = (string) Str::uuid();
    DB::table('invoices')->insert([
        'id' => $invoiceId,
        'organization_id' => $organization->id,
        'billing_entity_id' => $organization->defaultBillingEntity->id,
        'customer_id' => $customer->id,
        'status' => 0, // draft
        'issuing_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $invoiceId;
}

it('updates the tax', function (): void {
    [$organization, $tax] = updateTaxFixture();

    $result = UpdateService::call(tax: $tax, params: [
        'code' => 'updated code',
        'rate' => 15.0,
        'description' => 'updated desc',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->tax)->toBeInstanceOf(Tax::class)
        ->and($result->tax->name)->toBe($tax->name)
        ->and($result->tax->code)->toBe('updated code')
        ->and($result->tax->rate)->toBe(15.0)
        ->and($result->tax->description)->toBe('updated desc');
})->group('ledger:svc:Taxes.UpdateService');

it('fails when the tax is not found', function (): void {
    $result = UpdateService::call(tax: null, params: ['code' => 'x']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('tax_not_found');
});

it('returns a validation error when the name is blank', function (): void {
    [, $tax] = updateTaxFixture();

    $result = UpdateService::call(tax: $tax, params: ['name' => null, 'code' => 'code']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});

it('marks the draft invoices of the applicable customers as ready to be refreshed', function (): void {
    [$organization, $tax] = updateTaxFixture();

    $customer = Customer::factory()->for($organization)->create();
    $customer->appliedTaxes()->create([
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
    ]);
    $invoiceId = draftInvoiceForCustomer($organization, $customer);

    UpdateService::call(tax: $tax, params: ['rate' => 12.0]);

    expect(DB::table('invoices')->where('id', $invoiceId)->value('ready_to_be_refreshed'))->toBeTrue();
});

it('flags applied_to_organization when updated to true', function (): void {
    [$organization, $tax] = updateTaxFixture();

    $result = UpdateService::call(tax: $tax, params: ['applied_to_organization' => true]);

    expect($result->success())->toBeTrue()
        ->and($result->tax->fresh()->applied_to_organization)->toBeTrue();
});

// TODO(port): the BillingEntities::Taxes::{Apply,Remove}TaxesService scenarios
// from the Rails spec (applied tax rows created/removed on the default billing
// entity when applied_to_organization changes) are covered by the TODO(port)
// hook point in the service.
