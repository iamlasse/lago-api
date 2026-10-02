<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Support\CurrentContext;
use Database\Factories\TaxFactory;

beforeEach(function () {
    CurrentContext::reset();
});

function taxOrganization(): Organization
{
    return CurrentContext::$organization = Organization::factory()->create();
}

it('is valid with the factory defaults', function () {
    $tax = TaxFactory::new()->for(taxOrganization())->make();

    expect($tax->validateAttributes())->toBe([]);
});

it('requires a name, a rate and a code', function () {
    $tax = TaxFactory::new()->for(taxOrganization())->make([
        'name' => null,
        'rate' => null,
        'code' => '',
    ]);

    expect($tax->validateAttributes())->toBe([
        'name' => ['value_is_mandatory'],
        'rate' => ['value_is_mandatory'],
        'code' => ['value_is_mandatory'],
    ]);
});

it('accepts a zero rate', function () {
    $tax = TaxFactory::new()->for(taxOrganization())->make(['rate' => 0.0]);

    expect($tax->validateAttributes())->toBe([]);
});

it('validates the uniqueness of the code per organization, ignoring discarded taxes', function () {
    $organization = taxOrganization();
    $otherOrganization = Organization::factory()->create();

    TaxFactory::new()->for($organization)->create(['code' => 'vat']);
    TaxFactory::new()->for($organization)->create(['code' => 'old-vat', 'deleted_at' => now()]);

    expect(TaxFactory::new()->for($organization)->make(['code' => 'vat'])->validateAttributes())
        ->toBe(['code' => ['value_already_exist']])
        ->and(TaxFactory::new()->for($otherOrganization)->make(['code' => 'vat'])->validateAttributes())
        ->toBe([])
        ->and(TaxFactory::new()->for($organization)->make(['code' => 'old-vat'])->validateAttributes())
        ->toBe([]);
});

it('scopes applied_to_organization', function () {
    $organization = taxOrganization();

    $flagged = TaxFactory::new()->for($organization)->appliedToOrganization()->create();
    TaxFactory::new()->for($organization)->create();

    expect($organization->taxes()->appliedToOrganization()->pluck('id')->all())->toBe([$flagged->id]);
});

it('scopes applied_to_billing_entity', function () {
    $organization = taxOrganization();
    $billingEntity = $organization->defaultBillingEntity;

    $applied = TaxFactory::new()->for($organization)->appliedToBillingEntity()->create();
    TaxFactory::new()->for($organization)->create();

    expect($organization->taxes()->appliedToBillingEntity($billingEntity)->pluck('id')->all())->toBe([$applied->id]);
});

it('lists the billing entities carrying the tax', function () {
    $organization = taxOrganization();
    $default = $organization->defaultBillingEntity;
    $other = BillingEntity::factory()->for($organization)->create();

    $tax = TaxFactory::new()->for($organization)
        ->appliedToBillingEntity($default)
        ->appliedToBillingEntity($other)
        ->create();

    expect($tax->billingEntities()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$default->id, $other->id])->sort()->values()->all());
});

it('counts the attached customers when not applied to a billing entity', function () {
    $organization = taxOrganization();
    $tax = TaxFactory::new()->for($organization)->create();

    $customer = Customer::factory()->for($organization)->create();
    $customer->appliedTaxes()->create([
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
    ]);
    Customer::factory()->for($organization)->create();

    expect($tax->customersCount())->toBe(1);
});

it('counts the customers of the billing entities carrying the tax plus the attached ones', function () {
    $organization = taxOrganization();
    $default = $organization->defaultBillingEntity;
    $other = BillingEntity::factory()->for($organization)->create();

    $tax = TaxFactory::new()->for($organization)
        ->appliedToBillingEntity($default)
        ->appliedToBillingEntity($other)
        ->create();

    // A customer of the default entity with another tax applied is not counted.
    $withOtherTax = Customer::factory()->for($organization)->create();
    $otherTax = TaxFactory::new()->for($organization)->create();
    $withOtherTax->appliedTaxes()->create([
        'tax_id' => $otherTax->id,
        'organization_id' => $organization->id,
    ]);

    // Customers of the covered entities without any applied tax are counted.
    $covered1 = Customer::factory()->for($organization)->create();
    $covered2 = Customer::factory()->for($organization)->create(['billing_entity_id' => $other->id]);

    // A customer of an entity not carrying the tax is not counted.
    $thirdEntity = BillingEntity::factory()->for($organization)->create();
    Customer::factory()->for($organization)->create(['billing_entity_id' => $thirdEntity->id]);

    // Attached customers are counted whatever their billing entity.
    $attached = Customer::factory()->for($organization)->create(['billing_entity_id' => $thirdEntity->id]);
    $attached->appliedTaxes()->create([
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
    ]);

    expect($tax->customersCount())->toBe(3)
        ->and($tax->applicableCustomers()->pluck('customers.id')->sort()->values()->all())
        ->toBe(collect([$covered1->id, $covered2->id, $attached->id])->sort()->values()->all());
});
