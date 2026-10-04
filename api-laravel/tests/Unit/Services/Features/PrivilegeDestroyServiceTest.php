<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Services\Failures\NotFoundFailure;
use App\Services\Entitlements\PrivilegeDestroyService;

beforeEach(function (): void {
    CurrentContext::reset();
});

/**
 * Port of Rails' spec/services/entitlement/privilege_destroy_service_spec.rb
 * — discards the privilege and its entitlement values.
 */
it('discards the privilege and its values', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = Feature::factory()->forOrganization($organization)->create(['code' => 'seats']);
    $privilege = Privilege::factory()->forFeature($feature)->create(['code' => 'max', 'value_type' => 'integer']);
    $entitlement = App\Models\Entitlement::factory()->forOrganization($organization)->forFeature($feature)->create();
    App\Models\EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)
        ->create(['value' => '10']);

    $result = PrivilegeDestroyService::call(privilege: $privilege);

    expect($result->success())->toBeTrue()
        ->and($result->privilege->code)->toBe('max')
        ->and(Privilege::query()->count())->toBe(0)
        ->and(App\Models\EntitlementValue::query()->count())->toBe(0)
        ->and(Feature::query()->count())->toBe(1);
})->group('ledger:svc:Entitlements.PrivilegeDestroyService');

it('returns a not found failure when the privilege is missing', function (): void {
    $result = PrivilegeDestroyService::call(privilege: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('privilege');
});
