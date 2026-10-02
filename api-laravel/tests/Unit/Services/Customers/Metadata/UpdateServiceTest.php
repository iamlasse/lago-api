<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Services\Failures\ValidationFailure;
use App\Services\Customers\Metadata\UpdateService;

beforeEach(function () {
    CurrentContext::reset();
});

function metadataContext(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();

    return [$organization, $customer];
}

function metadataRow(Customer $customer, string $key, string $value = 'v'): object
{
    return $customer->metadata()->create([
        'key' => $key,
        'value' => $value,
        'display_in_invoice' => false,
        'organization_id' => $customer->organization_id,
    ]);
}

it('creates metadata without ids', function () {
    [, $customer] = metadataContext();

    $result = UpdateService::call(customer: $customer, params: [
        ['key' => 'k1', 'value' => 'v1', 'display_in_invoice' => true],
        ['key' => 'k2', 'value' => 'v2'],
    ]);

    expect($result->success())->toBeTrue()
        ->and($customer->metadata()->count())->toBe(2)
        ->and($customer->metadata()->where('key', 'k1')->first()->display_in_invoice)->toBeTrue()
        ->and($customer->metadata()->where('key', 'k2')->first()->display_in_invoice)->toBeFalse();
})->group('ledger:svc:Customers.Metadata.UpdateService');

it('updates metadata by id', function () {
    [, $customer] = metadataContext();
    $row = metadataRow($customer, 'k1', 'old');

    $result = UpdateService::call(customer: $customer, params: [
        ['id' => $row->id, 'key' => 'k1', 'value' => 'new', 'display_in_invoice' => true],
    ]);

    expect($result->success())->toBeTrue()
        ->and($row->fresh()->value)->toBe('new')
        ->and($row->fresh()->display_in_invoice)->toBeTrue()
        ->and($customer->metadata()->count())->toBe(1);
});

it('deletes metadata absent from the payload', function () {
    [, $customer] = metadataContext();
    $kept = metadataRow($customer, 'k1');
    $removed = metadataRow($customer, 'k2');

    $result = UpdateService::call(customer: $customer, params: [
        ['id' => $kept->id, 'key' => 'k1', 'value' => 'v'],
    ]);

    expect($result->success())->toBeTrue()
        ->and($customer->metadata()->count())->toBe(1)
        ->and($customer->metadata()->first()->id)->toBe($kept->id)
        ->and($removed->fresh())->toBeNull();
});

it('keeps newly created metadata through the sanitization', function () {
    [, $customer] = metadataContext();
    metadataRow($customer, 'gone');

    $result = UpdateService::call(customer: $customer, params: [
        ['key' => 'fresh', 'value' => 'v'],
    ]);

    expect($result->success())->toBeTrue()
        ->and($customer->metadata()->pluck('key')->all())->toBe(['fresh']);
});

it('rejects a too long key or value', function () {
    [, $customer] = metadataContext();

    $result = UpdateService::call(customer: $customer, params: [
        ['key' => str_repeat('k', 21), 'value' => 'v'],
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['key'])->toBe(['value_is_too_long']);

    $result = UpdateService::call(customer: $customer, params: [
        ['key' => 'k', 'value' => str_repeat('v', 101)],
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages['value'])->toBe(['value_is_too_long']);
});

it('rejects duplicate keys on the same customer', function () {
    [, $customer] = metadataContext();
    metadataRow($customer, 'dup');

    $result = UpdateService::call(customer: $customer, params: [
        ['key' => 'dup', 'value' => 'other'],
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages['key'])->toBe(['value_already_exist']);
});
