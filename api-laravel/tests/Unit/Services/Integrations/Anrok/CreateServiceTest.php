<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Failures\ForbiddenFailure;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\AnrokIntegration;
use App\Services\Integrations\Anrok\CreateService;

/**
 * Port of Rails' spec/services/integrations/anrok/create_service_spec.rb —
 * Anrok is a NON-premium integration, so the only gate is the license.
 */
it('does not create an integration without a premium license', function (): void {
    // The test config defaults the license token to premium-on; Rails' spec
    // runs without LAGO_LICENSE here.
    config(['lago.license' => null]);

    $organization = Organization::factory()->create();

    $result = CreateService::call(
        name: 'Anrok 1',
        code: 'anrok1',
        organization_id: $organization->id,
        connection_id: 'conn1',
        api_key: '123456789',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ForbiddenFailure::class);

    expect(AnrokIntegration::query()->count())->toBe(0);
});

it('creates an integration with a premium license', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();

    $result = CreateService::call(
        name: 'Anrok 1',
        code: 'anrok1',
        organization_id: $organization->id,
        connection_id: 'conn1',
        api_key: '123456789',
    );

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(AnrokIntegration::class)
        ->and($result->integration->name)->toBe('Anrok 1')
        ->and($result->integration->connectionId())->toBe('conn1')
        ->and($result->integration->apiKey())->toBe('123456789');

    expect(AnrokIntegration::query()->count())->toBe(1);
});

it('returns a validation failure for a missing name', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();

    $result = CreateService::call(
        name: null,
        code: 'anrok1',
        organization_id: $organization->id,
        connection_id: 'conn1',
        api_key: '123456789',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});
