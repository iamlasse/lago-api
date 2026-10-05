<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Support\Facades\Queue;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\AvalaraIntegration;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Jobs\Integrations\Avalara\FetchCompanyIdJob;
use App\Services\Integrations\Avalara\CreateService;

/**
 * Port of Rails' spec/services/integrations/avalara/create_service_spec.rb —
 * Avalara is a PREMIUM integration gated on Organization#avalara_enabled?.
 */
it('does not create an integration without the avalara premium flag', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();

    $result = CreateService::call(params: [
        'name' => 'Avalara 1',
        'code' => 'avalara1',
        'organization_id' => $organization->id,
        'company_code' => 'DEFAULT',
        'connection_id' => 'conn1',
        'account_id' => 'account1',
        'license_key' => 'license1',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    expect(AvalaraIntegration::query()->count())->toBe(0);
});

it('creates an integration and schedules the company id fetch', function (): void {
    Queue::fake();

    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['avalara']]);

    $result = CreateService::call(params: [
        'name' => 'Avalara 1',
        'code' => 'avalara1',
        'organization_id' => $organization->id,
        'company_code' => 'DEFAULT',
        'connection_id' => 'conn1',
        'account_id' => 'account1',
        'license_key' => 'license1',
    ]);

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(AvalaraIntegration::class)
        ->and($result->integration->companyCode())->toBe('DEFAULT')
        ->and($result->integration->accountId())->toBe('account1')
        ->and($result->integration->connectionId())->toBe('conn1')
        ->and($result->integration->licenseKey())->toBe('license1');

    Queue::assertPushed(FetchCompanyIdJob::class);
});

it('returns a validation failure for a missing license key', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['avalara']]);

    $result = CreateService::call(params: [
        'name' => 'Avalara 1',
        'code' => 'avalara1',
        'organization_id' => $organization->id,
        'company_code' => 'DEFAULT',
        'connection_id' => 'conn1',
        'account_id' => 'account1',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['license_key'])->toBe(['value_is_mandatory']);
});
