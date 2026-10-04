<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Models\EntitlementValue;
use App\Services\Features\DestroyService;
use App\Services\Failures\NotFoundFailure;

beforeEach(function (): void {
    CurrentContext::reset();
});

/**
 * Port of Rails' spec/services/entitlement/feature_destroy_service_spec.rb
 * — a discard cascade: values, entitlements, privileges, then the feature.
 */
it('discards the feature and its cascade', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = Feature::factory()->forOrganization($organization)->create(['code' => 'seats']);
    $privilege = Privilege::factory()->forFeature($feature)->create(['code' => 'max']);
    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '5']);

    $result = DestroyService::call(feature: $feature);

    expect($result->success())->toBeTrue()
        ->and($result->feature->code)->toBe('seats')
        ->and(Feature::query()->count())->toBe(0)
        ->and(Privilege::query()->count())->toBe(0)
        ->and(Entitlement::query()->count())->toBe(0)
        ->and(EntitlementValue::query()->count())->toBe(0)
        // Soft deletes — the rows are kept with deleted_at.
        ->and(Feature::withTrashed()->count())->toBe(1)
        ->and(Privilege::withTrashed()->count())->toBe(1);
})->group('ledger:svc:Features.DestroyService');

it('returns a not found failure when the feature is missing', function (): void {
    $result = DestroyService::call(feature: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('feature');
});
