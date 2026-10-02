<?php

declare(strict_types=1);

use App\Models\Tax;
use App\Models\Customer;
use App\Models\Organization;
use App\Support\CurrentContext;
use Database\Factories\TaxFactory;
use App\Services\Failures\ServiceFailure;
use App\Services\Customers\EuAutoTaxesService;
use App\Services\Failures\MethodNotAllowedFailure;

beforeEach(function () {
    CurrentContext::reset();
});

function euTaxContext(bool $euTaxManagement = true, string $entityCountry = 'FR'): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $billingEntity = $organization->defaultBillingEntity;
    $billingEntity->update(['eu_tax_management' => $euTaxManagement, 'country' => $entityCountry]);

    $customer = Customer::factory()->for($organization)->create(['billing_entity_id' => $billingEntity->id]);

    return [$organization, $billingEntity, $customer];
}

it('requires eu tax management on the billing entity', function () {
    [, , $customer] = euTaxContext(euTaxManagement: false);

    $result = EuAutoTaxesService::call(customer: $customer, newRecord: true, taxAttributesChanged: true);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
        ->and($result->getError()->code)->toBe('eu_tax_not_applicable');
})->group('ledger:svc:Customers.EuAutoTaxesService');

it('assigns the billing entity standard tax when the customer has no country', function () {
    [, , $customer] = euTaxContext();

    $customer->country = null;

    $result = EuAutoTaxesService::call(customer: $customer, newRecord: true, taxAttributesChanged: true);

    expect($result->success())->toBeTrue()
        ->and($result->tax_code)->toBe('lago_eu_fr_standard');
});

it('assigns the customer country standard tax for EU countries', function () {
    [, , $customer] = euTaxContext();

    $customer->country = 'DE';

    $result = EuAutoTaxesService::call(customer: $customer, newRecord: true, taxAttributesChanged: true);

    expect($result->success())->toBeTrue()
        ->and($result->tax_code)->toBe('lago_eu_de_standard');
});

it('assigns the tax exempt tax for non EU customer countries', function () {
    [, , $customer] = euTaxContext();

    $customer->country = 'US';

    $result = EuAutoTaxesService::call(customer: $customer, newRecord: true, taxAttributesChanged: true);

    expect($result->success())->toBeTrue()
        ->and($result->tax_code)->toBe('lago_eu_tax_exempt');
});

it('detects special territories by postcode', function () {
    // Canary Islands exception (Spain): postcode 35xxx / 38xxx.
    [, , $customer] = euTaxContext(entityCountry: 'ES');

    $customer->country = 'ES';
    $customer->zipcode = '35500';

    $result = EuAutoTaxesService::call(customer: $customer, newRecord: true, taxAttributesChanged: true);

    expect($result->success())->toBeTrue()
        ->and($result->tax_code)->toStartWith('lago_eu_es_exception_');
});

it('does not detect the FR B2B-only territories without a VAT number', function () {
    // France overseas (Corse exception is not B2B only; use a FR exception)
    [, , $customer] = euTaxContext(entityCountry: 'FR');

    $customer->country = 'FR';

    // FR has no postcode exceptions in the dataset — falls through to standard.
    $result = EuAutoTaxesService::call(customer: $customer, newRecord: true, taxAttributesChanged: true);

    expect($result->success())->toBeTrue()
        ->and($result->tax_code)->toBe('lago_eu_fr_standard');
});

it('returns a pending VIES failure when a tax identification number is set', function () {
    [, , $customer] = euTaxContext();

    $customer->tax_identification_number = 'DE123456789';

    $result = EuAutoTaxesService::call(customer: $customer, newRecord: true, taxAttributesChanged: true);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ServiceFailure::class)
        ->and($result->getError()->code)->toBe('vies_check_pending');
});

it('does not reapply when eu taxes already exist and attributes are unchanged', function () {
    [, $billingEntity, $customer] = euTaxContext();

    TaxFactory::new()->create(['organization_id' => CurrentContext::$organization->id,
        'code' => 'lago_eu_fr_standard',
        'auto_generated' => true,
    ]);

    $customer->appliedTaxes()->create([
        'tax_id' => Tax::where('code', 'lago_eu_fr_standard')->first()->id,
        'organization_id' => $customer->organization_id,
    ]);

    $result = EuAutoTaxesService::call(customer: $customer, newRecord: false, taxAttributesChanged: false);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    // ...but re-applies when the tax attributes changed.
    $customer->country = 'DE';

    $result = EuAutoTaxesService::call(customer: $customer, newRecord: false, taxAttributesChanged: true);

    expect($result->success())->toBeTrue()
        ->and($result->tax_code)->toBe('lago_eu_de_standard');
});
