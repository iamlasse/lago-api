<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/analytics/usage',
    'ledger:rest:GET:/api/v2/analytics/usage',
);

use App\Models\Organization;
use Illuminate\Support\Facades\Http;

/**
 * Port of Rails' spec/requests/api/v1/data_api/usages_controller_spec.rb
 * (GET /api/v1/analytics/usage, served by Api::V1::DataApi::UsagesController
 * proxying the Lago Data API) plus the api-permissions and beta-header
 * behaviors of the shared suite.
 */
function analyticsUsageOrganization(): array
{
    $organization = Organization::factory()->create();

    return [$organization, $organization->apiKeys()->first()];
}

function analyticsUsageDataApi(): array
{
    return [
        [
            'start_of_period_dt' => '2024-01-01',
            'end_of_period_dt' => '2024-01-31',
            'billable_metric_code' => 'account_members',
            'amount_currency' => 'EUR',
            'amount_cents' => 26600,
            'units' => 266,
        ],
        [
            'start_of_period_dt' => '2024-01-01',
            'end_of_period_dt' => '2024-01-31',
            'billable_metric_code' => 'accounts',
            'amount_currency' => 'EUR',
            'amount_cents' => 145950,
            'units' => 1459,
        ],
    ];
}

it('proxies the data api usage under the usages key', function (): void {
    config([
        'lago.data_api_url' => 'https://data.lago.test',
        'lago.data_api_bearer_token' => 'data-api-bearer',
        'lago.license' => 'premium-license-token',
    ]);

    [$organization, $apiKey] = analyticsUsageOrganization();

    Http::fake(['data.lago.test/*' => Http::response(analyticsUsageDataApi(), 200)]);

    $this->getJson(
        '/api/v1/analytics/usage?currency=EUR&billable_metric_code=account_members',
        ['Authorization' => 'Bearer '.$apiKey->value],
    )
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->has('usages', 2)
                ->where('usages.0.billable_metric_code', 'account_members')
                ->where('usages.0.amount_currency', 'EUR')
                ->where('usages.0.amount_cents', 26600)
                ->where('usages.0.units', 266);
        })
        // Rails renders the raw proxy JSON; the beta header is v2-only.
        ->assertHeaderMissing('X-Lago-Endpoint-Status');

    Http::assertSent(function (Illuminate\Http\Client\Request $request) use ($organization): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://data.lago.test/usages/'.$organization->id
                .'/?currency=EUR&billable_metric_code=account_members&time_granularity=daily'
            && $request->hasHeader('Authorization', 'Bearer data-api-bearer');
    });
});

it('still serves the usage without a premium license (the service filters params instead)', function (): void {
    config([
        'lago.data_api_url' => 'https://data.lago.test',
        'lago.data_api_bearer_token' => 'data-api-bearer',
    ]);

    [$organization, $apiKey] = analyticsUsageOrganization();

    Http::fake(['data.lago.test/*' => Http::response(analyticsUsageDataApi(), 200)]);

    $this->getJson(
        '/api/v1/analytics/usage?currency=EUR&time_granularity=weekly',
        ['Authorization' => 'Bearer '.$apiKey->value],
    )->assertOk();

    $startDate = now()->subDays(30)->toDateString();

    // Non-premium: pinned to daily granularity over the last 30 days,
    // currency dropped, only the billable_metric_code filter kept.
    Http::assertSent(function (Illuminate\Http\Client\Request $request) use ($organization, $startDate): bool {
        return $request->url() === 'https://data.lago.test/usages/'.$organization->id
            .'/?time_granularity=daily&start_of_period_dt='.$startDate;
    });
});

it('mirrors the route at v2 with the beta header', function (): void {
    config([
        'lago.data_api_url' => 'https://data.lago.test',
        'lago.data_api_bearer_token' => 'data-api-bearer',
    ]);

    [$organization, $apiKey] = analyticsUsageOrganization();

    Http::fake(['data.lago.test/*' => Http::response([], 200)]);

    $this->getJson(
        '/api/v2/analytics/usage',
        ['Authorization' => 'Bearer '.$apiKey->value],
    )
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});

it('requires the analytic read api permission', function (): void {
    config([
        'lago.data_api_url' => 'https://data.lago.test',
        'lago.data_api_bearer_token' => 'data-api-bearer',
        'lago.license' => 'premium-license-token',
    ]);

    [$organization, $apiKey] = analyticsUsageOrganization();

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );

    // The key grants nothing.
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['customers' => ['read']]), $apiKey->id],
    );

    Http::fake(['data.lago.test/*' => Http::response([], 200)]);

    $this->getJson(
        '/api/v1/analytics/usage',
        ['Authorization' => 'Bearer '.$apiKey->value],
    )
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'read_action_not_allowed_for_analytic',
        ]);

    Http::assertNothingSent();
});

it('allows the read when the analytic permission grants it', function (): void {
    config([
        'lago.data_api_url' => 'https://data.lago.test',
        'lago.data_api_bearer_token' => 'data-api-bearer',
        'lago.license' => 'premium-license-token',
    ]);

    [$organization, $apiKey] = analyticsUsageOrganization();

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['analytic' => ['read']]), $apiKey->id],
    );

    Http::fake(['data.lago.test/*' => Http::response(analyticsUsageDataApi(), 200)]);

    $this->getJson(
        '/api/v1/analytics/usage',
        ['Authorization' => 'Bearer '.$apiKey->value],
    )->assertOk()->assertJsonPath('usages.1.billable_metric_code', 'accounts');
});

it('renders a 500 when the data api answers a non-success code (Rails leaves the HttpError unrescued)', function (): void {
    config([
        'lago.data_api_url' => 'https://data.lago.test',
        'lago.data_api_bearer_token' => 'data-api-bearer',
    ]);

    [$organization, $apiKey] = analyticsUsageOrganization();

    Http::fake(['data.lago.test/*' => Http::response('unauthorized', 401)]);

    $this->getJson(
        '/api/v1/analytics/usage',
        ['Authorization' => 'Bearer '.$apiKey->value],
    )->assertStatus(500);
});
