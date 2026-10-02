<?php

declare(strict_types=1);

use App\Models\Tax;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Services\Taxes\CreateService;
use App\Services\Failures\ValidationFailure;

beforeEach(function (): void {
    CurrentContext::reset();
});

function createTaxParams(array $overrides = []): array
{
    return [
        'name' => 'Tax',
        'code' => 'tax_code',
        'rate' => 15.0,
        'description' => 'Tax Description',
        ...$overrides,
    ];
}

it('creates a tax', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createTaxParams());

    expect($result->success())->toBeTrue()
        ->and(Tax::count())->toBe(1)
        ->and($result->tax)->toBeInstanceOf(Tax::class)
        ->and($result->tax->name)->toBe('Tax')
        ->and($result->tax->code)->toBe('tax_code')
        ->and($result->tax->rate)->toBe(15.0)
        ->and($result->tax->applied_to_organization)->toBeFalse();
})->group('ledger:svc:Taxes.CreateService');

it('does not flag the tax as applied to the organization by default', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createTaxParams());

    expect($result->tax->fresh()->applied_to_organization)->toBeFalse();
});

it('creates a tax applied to the organization', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(
        organization: $organization,
        params: createTaxParams(['applied_to_organization' => true]),
    );

    expect($result->success())->toBeTrue()
        ->and($result->tax->fresh()->applied_to_organization)->toBeTrue();
});

it('returns a validation error when the code already exists', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    Tax::factory()->for($organization)->create(['code' => 'tax_code']);

    $result = CreateService::call(organization: $organization, params: createTaxParams());

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['code'])->toBe(['value_already_exist']);
});

it('returns a validation error when the rate is missing', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createTaxParams(['rate' => null]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['rate'])->toBe(['value_is_mandatory']);
});

// TODO(port): the BillingEntities::Taxes::ApplyTaxesService scenarios from the
// Rails spec (applied tax rows created on the default billing entity when
// applied_to_organization is true) are covered by the TODO(port) hook point in
// the service.
