<?php

declare(strict_types=1);

use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ForbiddenFailure;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\AnrokIntegration;
use App\Services\Integrations\Anrok\UpdateService;

/**
 * Port of Rails' spec/services/integrations/anrok/update_service_spec.rb.
 */
it('returns not found without an integration', function (): void {
    config()->set('lago.license', 'premium-token');

    $result = UpdateService::call(integration: null, params: ['name' => 'X']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('does not update without a premium license', function (): void {
    config(['lago.license' => null]);

    $integration = AnrokIntegration::factory()->create();

    $result = UpdateService::call(integration: $integration, params: ['name' => 'New name']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ForbiddenFailure::class);
});

it('updates the name, code and api key', function (): void {
    config()->set('lago.license', 'premium-token');

    $integration = AnrokIntegration::factory()->create();

    $result = UpdateService::call(integration: $integration, params: [
        'name' => 'New name',
        'code' => 'new_code',
        'api_key' => 'new_api_key',
    ]);

    expect($result->failure())->toBeFalse();

    $integration->refresh();

    expect($integration->name)->toBe('New name')
        ->and($integration->code)->toBe('new_code')
        ->and($integration->apiKey())->toBe('new_api_key')
        // The untouched secret accessor survives the update.
        ->and($integration->connectionId())->toBe($integration->connectionId());
});

it('returns a validation failure for a blanked name', function (): void {
    config()->set('lago.license', 'premium-token');

    $integration = AnrokIntegration::factory()->create();

    $result = UpdateService::call(integration: $integration, params: ['name' => null]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});
