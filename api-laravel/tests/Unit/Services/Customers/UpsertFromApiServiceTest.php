<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Support\CurrentContext;
use Database\Factories\TaxFactory;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\Customers\UpsertFromApiService;

beforeEach(function () {
    CurrentContext::reset();
    CurrentContext::$source = 'api';
});

function upsertContext(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $externalId = (string) Illuminate\Support\Str::uuid();

    return [$organization, $externalId];
}

function upsertArgs(string $externalId): array
{
    return [
        'external_id' => $externalId,
        'name' => 'Foo Bar',
        'currency' => 'EUR',
    ];
}

it('creates a new customer on the default billing entity', function () {
    [$organization, $externalId] = upsertContext();

    $result = UpsertFromApiService::call(organization: $organization, params: upsertArgs($externalId));

    expect($result->success())->toBeTrue();

    $customer = $result->customer;

    expect($customer->exists)->toBeTrue()
        ->and($customer->external_id)->toBe($externalId)
        ->and($customer->billing_entity_id)->toBe($organization->defaultBillingEntity->id)
        ->and($customer->currency)->toBe('EUR');
})->group('ledger:svc:Customers.UpsertFromApiService');

it('updates the existing customer when external_id already exists', function () {
    [$organization, $externalId] = upsertContext();

    $existing = Customer::factory()->for($organization)->create([
        'external_id' => $externalId,
        'name' => 'Original',
    ]);

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: ['name' => 'Updated Name'] + upsertArgs($externalId),
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->id)->toBe($existing->id)
        ->and($result->customer->fresh()->name)->toBe('Updated Name')
        ->and(Customer::query()->where('external_id', $externalId)->count())->toBe(1);
});

it('only assigns attributes present in the params on update', function () {
    [$organization, $externalId] = upsertContext();

    $existing = Customer::factory()->for($organization)->create([
        'external_id' => $externalId,
        'name' => 'Original',
        'email' => 'original@example.com',
        'city' => 'Lyon',
        'legal_name' => 'Original Corp',
    ]);

    // Only `name` and a null `email` are sent: email is overwritten with nil,
    // city and legal_name are untouched.
    $result = UpsertFromApiService::call(
        organization: $organization,
        params: ['external_id' => $externalId, 'name' => 'Updated', 'email' => null],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->fresh()->name)->toBe('Updated')
        ->and($result->customer->fresh()->email)->toBeNull()
        ->and($result->customer->fresh()->city)->toBe('Lyon')
        ->and($result->customer->fresh()->legal_name)->toBe('Original Corp');
});

it('defaults finalize_zero_amount_invoice to inherit when present but nil', function () {
    [$organization, $externalId] = upsertContext();

    $existing = Customer::factory()->for($organization)->create(['external_id' => $externalId]);

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: ['external_id' => $externalId, 'finalize_zero_amount_invoice' => 'skip'],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->fresh()->finalize_zero_amount_invoice->label())->toBe('skip');

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: ['external_id' => $externalId, 'finalize_zero_amount_invoice' => null],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->fresh()->finalize_zero_amount_invoice->label())->toBe('inherit');
});

it('rejects an invalid finalize_zero_amount_invoice value', function () {
    [$organization, $externalId] = upsertContext();

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: upsertArgs($externalId) + ['finalize_zero_amount_invoice' => 'invalid'],
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['finalize_zero_amount_invoice' => ['invalid_value']]);
});

it('rejects more than five metadata entries', function () {
    [$organization, $externalId] = upsertContext();

    $metadata = collect(range(1, 6))->map(fn ($i) => ['key' => "k$i", 'value' => 'v'])->all();

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: upsertArgs($externalId) + ['metadata' => $metadata],
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['metadata' => ['invalid_count']]);
});

it('creates metadata for a new customer', function () {
    [$organization, $externalId] = upsertContext();

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: upsertArgs($externalId) + ['metadata' => [['key' => 'k1', 'value' => 'v1']]],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->metadata()->count())->toBe(1)
        ->and($result->customer->metadata()->first()->key)->toBe('k1');
});

it('upserts metadata on an existing customer and removes removed keys', function () {
    [$organization, $externalId] = upsertContext();

    $existing = Customer::factory()->for($organization)->create(['external_id' => $externalId]);
    $metadata = $existing->metadata()->create([
        'key' => 'old',
        'value' => 'v',
        'display_in_invoice' => false,
        'organization_id' => $organization->id,
    ]);

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: [
            'external_id' => $externalId,
            'metadata' => [
                ['id' => $metadata->id, 'key' => 'old', 'value' => 'updated'],
                ['key' => 'added', 'value' => 'v2'],
            ],
        ],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->metadata()->count())->toBe(2)
        ->and($result->customer->metadata()->where('key', 'old')->first()->value)->toBe('updated');
});

it('rejects duplicated integration customer types', function () {
    [$organization, $externalId] = upsertContext();

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: upsertArgs($externalId) + ['integration_customers' => [
            ['integration_type' => 'netsuite'],
            ['integration_type' => 'netsuite'],
        ]],
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe([
            'integration_customers' => ['invalid_count_per_integration_type'],
        ]);
});

it('fails when the organization has no active billing entity', function () {
    [$organization, $externalId] = upsertContext();

    Illuminate\Support\Facades\DB::table('billing_entities')
        ->where('organization_id', $organization->id)
        ->update(['archived_at' => now()]);

    $result = UpsertFromApiService::call(organization: $organization, params: upsertArgs($externalId));

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('billing_entity');
});

it('resolves the billing entity by code when provided', function () {
    [$organization, $externalId] = upsertContext();
    $entity2 = BillingEntity::factory()->for($organization)->create();

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: upsertArgs($externalId) + ['billing_entity_code' => $entity2->code],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->billing_entity_id)->toBe($entity2->id);
});

it('changes the billing entity on update and carries over non eu taxes', function () {
    [$organization, $externalId] = upsertContext();
    $entity2 = BillingEntity::factory()->for($organization)->create();

    $existing = Customer::factory()->for($organization)->create(['external_id' => $externalId]);

    $tax = TaxFactory::new()->create(['organization_id' => $organization->id, 'code' => 'custom-tax']);
    $existing->appliedTaxes()->create(['tax_id' => $tax->id, 'organization_id' => $organization->id]);

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: ['external_id' => $externalId, 'billing_entity_code' => $entity2->code],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->billing_entity_id)->toBe($entity2->id)
        ->and($result->customer->taxes()->pluck('code')->all())->toBe(['custom-tax']);
});

it('fails on a validation error', function () {
    [$organization] = upsertContext();

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: ['external_id' => '', 'name' => 'Foo'],
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['external_id'])->toBe(['value_is_mandatory']);
});

it('applies eu auto taxes with the requested tax codes', function () {
    [$organization, $externalId] = upsertContext();
    $organization->defaultBillingEntity->update(['eu_tax_management' => true, 'country' => 'DE']);

    TaxFactory::new()->create(['organization_id' => $organization->id, 'code' => 'lago_eu_de_standard', 'auto_generated' => true]);

    $result = UpsertFromApiService::call(organization: $organization, params: upsertArgs($externalId));

    expect($result->success())->toBeTrue()
        ->and($result->customer->taxes()->pluck('code')->all())->toBe(['lago_eu_de_standard']);
});

it('upcases country and shipping country values', function () {
    [$organization, $externalId] = upsertContext();

    $result = UpsertFromApiService::call(
        organization: $organization,
        params: upsertArgs($externalId) + [
            'country' => 'fr',
            'shipping_address' => ['country' => 'de', 'city' => 'Berlin'],
        ],
    );

    expect($result->success())->toBeTrue()
        ->and($result->customer->country)->toBe('FR')
        ->and($result->customer->shipping_country)->toBe('DE')
        ->and($result->customer->shipping_city)->toBe('Berlin');
});
