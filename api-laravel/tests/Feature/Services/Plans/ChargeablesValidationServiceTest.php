<?php

declare(strict_types=1);

use App\Services\Plans\ChargeablesValidationService;

/**
 * Port of spec/services/plans/chargeables_validation_service_spec.rb — the
 * billable-metric / add-on existence checks run before the plan transaction.
 */
function chargeablesOrganization(): App\Models\Organization
{
    return App\Models\Organization::factory()->create();
}

it('succeeds without charges or fixed charges', function (): void {
    $result = ChargeablesValidationService::call(organization: chargeablesOrganization());

    expect($result->success())->toBeTrue();
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('succeeds when the referenced billable metric exists', function (): void {
    $organization = chargeablesOrganization();
    $metric = App\Models\BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $result = ChargeablesValidationService::call(organization: $organization, charges: [
        ['billable_metric_id' => $metric->id],
    ]);

    expect($result->success())->toBeTrue();
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('fails when a billable metric does not exist', function (): void {
    $result = ChargeablesValidationService::call(organization: chargeablesOrganization(), charges: [
        ['billable_metric_id' => 'non-existent-id'],
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()?->getMessage())->toBe('billable_metrics_not_found');
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('fails when some of the billable metrics do not exist', function (): void {
    $organization = chargeablesOrganization();
    $metric = App\Models\BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $result = ChargeablesValidationService::call(organization: $organization, charges: [
        ['billable_metric_id' => $metric->id],
        ['billable_metric_id' => 'non-existent-id'],
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()?->getMessage())->toBe('billable_metrics_not_found');
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('succeeds when the referenced add-on exists by id', function (): void {
    $organization = chargeablesOrganization();
    $addOn = App\Models\AddOn::factory()->create(['organization_id' => $organization->id]);

    $result = ChargeablesValidationService::call(organization: $organization, fixedCharges: [
        ['add_on_id' => $addOn->id],
    ]);

    expect($result->success())->toBeTrue();
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('succeeds when the referenced add-on exists by code', function (): void {
    $organization = chargeablesOrganization();
    $addOn = App\Models\AddOn::factory()->create(['organization_id' => $organization->id]);

    $result = ChargeablesValidationService::call(organization: $organization, fixedCharges: [
        ['add_on_code' => $addOn->code],
    ]);

    expect($result->success())->toBeTrue();
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('fails when the add-on id does not exist', function (): void {
    $result = ChargeablesValidationService::call(organization: chargeablesOrganization(), fixedCharges: [
        ['add_on_id' => 'non-existent-id'],
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()?->getMessage())->toBe('add_ons_not_found');
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('fails when the add-on code does not exist', function (): void {
    $result = ChargeablesValidationService::call(organization: chargeablesOrganization(), fixedCharges: [
        ['add_on_code' => 'non-existent-code'],
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()?->getMessage())->toBe('add_ons_not_found');
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('validates both an add-on id and an add-on code together', function (): void {
    $organization = chargeablesOrganization();
    $first = App\Models\AddOn::factory()->create(['organization_id' => $organization->id]);
    $second = App\Models\AddOn::factory()->create(['organization_id' => $organization->id]);

    $result = ChargeablesValidationService::call(organization: $organization, fixedCharges: [
        ['add_on_id' => $first->id],
        ['add_on_code' => $second->code],
    ]);

    expect($result->success())->toBeTrue();
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('ignores a nil add_on_id and validates by code', function (): void {
    $organization = chargeablesOrganization();
    $addOn = App\Models\AddOn::factory()->create(['organization_id' => $organization->id]);

    $result = ChargeablesValidationService::call(organization: $organization, fixedCharges: [
        ['add_on_id' => null, 'add_on_code' => $addOn->code],
    ]);

    expect($result->success())->toBeTrue();
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('ignores a nil add_on_code and validates by id', function (): void {
    $organization = chargeablesOrganization();
    $addOn = App\Models\AddOn::factory()->create(['organization_id' => $organization->id]);

    $result = ChargeablesValidationService::call(organization: $organization, fixedCharges: [
        ['add_on_id' => $addOn->id, 'add_on_code' => null],
    ]);

    expect($result->success())->toBeTrue();
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('validates charges and fixed charges together', function (): void {
    $organization = chargeablesOrganization();
    $metric = App\Models\BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $addOn = App\Models\AddOn::factory()->create(['organization_id' => $organization->id]);

    $result = ChargeablesValidationService::call(organization: $organization, charges: [
        ['billable_metric_id' => $metric->id],
    ], fixedCharges: [
        ['add_on_id' => $addOn->id],
    ]);

    expect($result->success())->toBeTrue();
})->group('ledger:svc:Plans.ChargeablesValidationService');

it('keeps the last recorded failure when both validations fail', function (): void {
    // Rails' not_found_failure! only records (fail_with_error! does not
    // raise), so the add-ons check overwrites the metrics failure.
    $result = ChargeablesValidationService::call(organization: chargeablesOrganization(), charges: [
        ['billable_metric_id' => 'non-existent-id'],
    ], fixedCharges: [
        ['add_on_id' => 'non-existent-id'],
    ]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()?->getMessage())->toBe('add_ons_not_found');
})->group('ledger:svc:Plans.ChargeablesValidationService');
