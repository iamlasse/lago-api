<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use App\Models\Integrations\AvalaraIntegration;
use App\Services\Integrations\Avalara\FetchCompanyIdService;

/**
 * Port of Rails' spec/services/integrations/avalara/
 * fetch_company_id_service_spec.rb — the wrapper service, over a stubbed
 * aggregator call.
 */
it('stamps the fetched company id onto the integration', function (): void {
    Http::fake([
        'https://api.nango.dev/v1/avalara/companies' => Http::response([
            'companies' => [['id' => 'DEFAULT-12345']],
        ]),
    ]);

    $integration = AvalaraIntegration::factory()->create();

    $result = FetchCompanyIdService::call(integration: $integration);

    expect($result->success())->toBeTrue();

    $integration->refresh();

    expect($integration->companyId())->toBe('DEFAULT-12345');
});

it('skips the fetch when the company id is already present', function (): void {
    Http::fake();

    $integration = AvalaraIntegration::factory()->create([
        'settings' => ['company_code' => 'DEFAULT', 'company_id' => 'KNOWN'],
    ]);

    $result = FetchCompanyIdService::call(integration: $integration);

    expect($result->success())->toBeTrue();
    Http::assertNothingSent();
});

it('returns without fetching for a non-avalara integration', function (): void {
    Http::fake();

    $integration = App\Models\Integrations\OktaIntegration::factory()->create();

    $result = FetchCompanyIdService::call(integration: $integration);

    expect($result->success())->toBeTrue();
    Http::assertNothingSent();
});
