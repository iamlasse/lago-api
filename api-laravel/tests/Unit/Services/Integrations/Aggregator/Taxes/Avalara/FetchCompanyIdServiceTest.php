<?php

declare(strict_types=1);

use App\Models\Integrations\AvalaraIntegration;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use App\Services\Integrations\Aggregator\Taxes\Avalara\FetchCompanyIdService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/services/integrations/aggregator/taxes/avalara/
 * fetch_company_id_service_spec.rb — the Nango companies lookup.
 */
beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    unset($_ENV['NANGO_SECRET_KEY']);
});

it('returns the company on success', function (): void {
    $organization = Organization::factory()->create();
    $integration = AvalaraIntegration::factory()->create(['organization_id' => $organization->id]);

    Http::fake([
        'https://api.nango.dev/v1/avalara/companies' => Http::response(
            file_get_contents(base_path('tests/fixtures/IntegrationAggregator/taxes/companies/success_response.json')),
        ),
    ]);

    $result = FetchCompanyIdService::call(integration: $integration);

    expect($result->success())->toBeTrue()
        ->and($result->company['id'])->toBe('DEFAULT-12345');
});

it('fails with company_not_found and delivers the integration error webhook', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    $integration = AvalaraIntegration::factory()->create(['organization_id' => $organization->id]);

    WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    Http::fake([
        'https://api.nango.dev/v1/avalara/companies' => Http::response(
            file_get_contents(base_path('tests/fixtures/IntegrationAggregator/taxes/companies/failed_response.json')),
        ),
    ]);

    $result = FetchCompanyIdService::call(integration: $integration);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('company_not_found');

    Queue::assertPushed(\App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'integration.provider_error');
});

it('maps a nango server error onto the error result', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    $integration = AvalaraIntegration::factory()->create(['organization_id' => $organization->id]);

    WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    Http::fake([
        'https://api.nango.dev/v1/avalara/companies' => Http::response(
            file_get_contents(base_path('tests/fixtures/IntegrationAggregator/error_response.json')),
            500,
        ),
    ]);

    $result = FetchCompanyIdService::call(integration: $integration);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('action_script_runtime_error')
        ->and($result->getError()->errorMessage)->toContain('submitFields');
});
