<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Support\CurrentContext;
use Database\Factories\TaxFactory;
use App\Services\Failures\NotFoundFailure;
use App\Services\Customers\UpdateService as CustomerUpdateService;

beforeEach(function (): void {
    CurrentContext::reset();
});

function customerUpdateContext(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create(['name' => 'Original']);

    return [$organization, $customer];
}

it('updates the provided attributes only', function (): void {
    [, $customer] = customerUpdateContext();

    $result = CustomerUpdateService::call(customer: $customer, args: [
        'name' => 'Renamed',
        'city' => 'Paris',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->customer->fresh()->name)->toBe('Renamed')
        ->and($result->customer->fresh()->city)->toBe('Paris')
        ->and($result->customer->fresh()->legal_name)->toBe($customer->legal_name);
})->group('ledger:svc:Customers.UpdateService');

it('fails when the customer is missing', function (): void {
    $result = CustomerUpdateService::call(customer: null, args: ['name' => 'X']);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('customer');
});

it('rejects more than five metadata entries', function (): void {
    [, $customer] = customerUpdateContext();

    $result = CustomerUpdateService::call(
        customer: $customer,
        args: ['metadata' => collect(range(1, 6))->map(fn ($i) => ['key' => "k$i", 'value' => 'v'])->all()],
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['metadata' => ['invalid_count']]);
});

it('upcases country and tax identification changes trigger eu taxes', function (): void {
    [$organization, $customer] = customerUpdateContext();
    $organization->defaultBillingEntity->update(['eu_tax_management' => true, 'country' => 'FR']);

    TaxFactory::new()->create(['organization_id' => $organization->id,
        'code' => 'lago_eu_de_standard',
        'auto_generated' => true,
    ]);

    $result = CustomerUpdateService::call(customer: $customer, args: ['country' => 'de']);

    expect($result->success())->toBeTrue()
        ->and($result->customer->country)->toBe('DE')
        ->and($result->customer->taxes()->pluck('code')->all())->toBe(['lago_eu_de_standard']);
});

it('applies requested tax codes on update', function (): void {
    [$organization, $customer] = customerUpdateContext();
    $tax = TaxFactory::new()->create(['organization_id' => $organization->id, 'code' => 'tax-update']);

    $result = CustomerUpdateService::call(customer: $customer, args: ['tax_codes' => ['tax-update']]);

    expect($result->success())->toBeTrue()
        ->and($result->customer->taxes()->pluck('code')->all())->toBe([$tax->code]);
});

it('changes the billing entity by code', function (): void {
    [$organization, $customer] = customerUpdateContext();
    $entity2 = BillingEntity::factory()->for($organization)->create();

    $result = CustomerUpdateService::call(
        customer: $customer,
        args: ['billing_entity_code' => $entity2->code],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->fresh()->billing_entity_id)->toBe($entity2->id);
});

it('fails on an unknown billing entity code', function (): void {
    [, $customer] = customerUpdateContext();

    $result = CustomerUpdateService::call(
        customer: $customer,
        args: ['billing_entity_code' => 'nope'],
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('cannot change external_id when attached to a subscription', function (): void {
    [$organization, $customer] = customerUpdateContext();

    $planId = Illuminate\Support\Str::uuid();
    Illuminate\Support\Facades\DB::table('plans')->insert([
        'id' => $planId,
        'organization_id' => $organization->id,
        'name' => 'Plan',
        'code' => 'plan-1',
        'amount_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Illuminate\Support\Facades\DB::table('subscriptions')->insert([
        'id' => Illuminate\Support\Str::uuid(),
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $planId,
        'status' => 1, // active
        'external_id' => 'sub-1',
        'name' => 'plan',
        'billing_time' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $newExternalId = 'changed-external-id';
    $result = CustomerUpdateService::call(
        customer: $customer,
        args: ['external_id' => $newExternalId],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->fresh()->external_id)->not->toBe($newExternalId);
});

it('updates metadata through the metadata service', function (): void {
    [, $customer] = customerUpdateContext();

    $result = CustomerUpdateService::call(
        customer: $customer,
        args: ['metadata' => [['key' => 'k', 'value' => 'v']]],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->metadata()->pluck('key')->all())->toBe(['k']);
});
