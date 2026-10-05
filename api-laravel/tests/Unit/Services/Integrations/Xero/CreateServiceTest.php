<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\XeroIntegration;
use App\Jobs\Integrations\Aggregator\PerformSyncJob;
use App\Services\Integrations\Xero\CreateService;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/services/integrations/xero/create_service_spec.rb —
 * Xero is a premium integration, so both the license and the organization's
 * "xero" premium_integrations flag gate the creation.
 */
it('does not create an integration without a premium license', function (): void {
    config(['lago.license' => null]);

    $organization = Organization::factory()->create();

    $result = CreateService::call(
        name: 'Xero 1',
        code: 'xero1',
        organization_id: $organization->id,
        connection_id: 'conn1',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    expect(XeroIntegration::query()->count())->toBe(0);
});

it('does not create an integration without the xero premium integration flag', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();

    $result = CreateService::call(
        name: 'Xero 1',
        code: 'xero1',
        organization_id: $organization->id,
        connection_id: 'conn1',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    expect(XeroIntegration::query()->count())->toBe(0);
});

it('creates an integration and enqueues the nango sync', function (): void {
    Queue::fake();

    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['xero']]);

    $result = CreateService::call(
        name: 'Xero 1',
        code: 'xero1',
        organization_id: $organization->id,
        connection_id: 'conn1',
        sync_credit_notes: true,
        sync_invoices: true,
    );

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(XeroIntegration::class)
        ->and($result->integration->name)->toBe('Xero 1')
        ->and($result->integration->connectionId())->toBe('conn1')
        ->and($result->integration->syncCreditNotes())->toBeTrue()
        ->and($result->integration->syncInvoices())->toBeTrue()
        ->and($result->integration->syncPayments())->toBeFalse();

    expect(XeroIntegration::query()->count())->toBe(1);

    Queue::assertPushed(PerformSyncJob::class);
});

it('returns a validation failure for a missing name', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['xero']]);

    $result = CreateService::call(
        name: null,
        code: 'xero1',
        organization_id: $organization->id,
        connection_id: 'conn1',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});
