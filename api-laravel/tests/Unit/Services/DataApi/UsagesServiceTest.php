<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\BillableMetric;
use App\Http\Client\LagoHttpError;
use Illuminate\Support\Facades\Http;
use App\Services\DataApi\UsagesService;
use Illuminate\Http\Client\ConnectionException;

/**
 * Port of Rails' spec/services/data_api/usages_service_spec.rb (the premium /
 * non-premium filtered_params scenarios, the discarded billable metric
 * annotation and the transient-error retry).
 */
beforeEach(function (): void {
    config([
        'lago.data_api_url' => 'https://data.lago.test',
        'lago.data_api_bearer_token' => 'data-api-bearer',
    ]);

    $this->organization = Organization::factory()->create();
});

// Rails' spec/fixtures/lago_data_api/usages.json.
function dataApiUsagesFixture(): array
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
        [
            'start_of_period_dt' => '2024-01-01',
            'end_of_period_dt' => '2024-01-31',
            'billable_metric_code' => 'business_account_opening',
            'amount_currency' => 'EUR',
            'amount_cents' => 2521800,
            'units' => 25218,
        ],
    ];
}

function premiumDataApi(callable $scenario): mixed
{
    config(['lago.license' => 'premium-license-token']);

    try {
        return $scenario();
    } finally {
        config(['lago.license' => null]);
    }
}

function dataApiUsagesCall(Organization $organization, array $params = []): App\Services\BaseResult
{
    Http::fake(['data.lago.test/*' => Http::response(dataApiUsagesFixture(), 200)]);

    return UsagesService::call(organization: $organization, params: $params);
}

it('gets the usages for the organization', function (): void {
    $result = dataApiUsagesCall($this->organization);

    expect($result->success())->toBeTrue()
        ->and($result->usages)->toHaveCount(3)
        ->and($result->usages[0])->toMatchArray([
            'billable_metric_code' => 'account_members',
            'amount_currency' => 'EUR',
            'amount_cents' => 26600,
            'units' => 266,
            'is_billable_metric_deleted' => false,
        ]);

    $expectedStartDate = now()->subDays(30)->toDateString();

    Http::assertSent(function (Illuminate\Http\Client\Request $request) use ($expectedStartDate): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://data.lago.test/usages/'.$this->organization->id
                .'/?time_granularity=daily&start_of_period_dt='.$expectedStartDate
            && $request->hasHeader('Authorization', 'Bearer data-api-bearer');
    });
});

it('annotates the deleted billable metrics', function (): void {
    BillableMetric::factory()->discarded()->create([
        'organization_id' => $this->organization->id,
        'code' => 'account_members',
    ]);

    BillableMetric::factory()->create([
        'organization_id' => $this->organization->id,
        'code' => 'active_metric',
    ]);

    $result = dataApiUsagesCall($this->organization);

    expect($result->success())->toBeTrue()
        ->and($result->usages[0]['billable_metric_code'])->toBe('account_members')
        ->and($result->usages[0]['is_billable_metric_deleted'])->toBeTrue()
        ->and($result->usages[1]['is_billable_metric_deleted'])->toBeFalse();
});

it('ignores discarded metrics from other organizations', function (): void {
    BillableMetric::factory()->discarded()->create([
        'organization_id' => Organization::factory()->create()->id,
        'code' => 'account_members',
    ]);

    $result = dataApiUsagesCall($this->organization);

    expect($result->usages[0]['is_billable_metric_deleted'])->toBeFalse();
});

// -- filtered_params ------------------------------------------------------------

it('keeps the billable metric code filter and drops the rest when not premium', function (): void {
    $result = dataApiUsagesCall($this->organization, [
        'billable_metric_code' => 'code',
        'time_granularity' => 'weekly',
        'from_date' => '2024-01-01',
        'additional_param' => 'value',
    ]);

    expect($result->success())->toBeTrue();

    $expectedStartDate = now()->subDays(30)->toDateString();

    Http::assertSent(function (Illuminate\Http\Client\Request $request) use ($expectedStartDate): bool {
        return $request->url() === 'https://data.lago.test/usages/'.$this->organization->id
            .'/?time_granularity=daily&start_of_period_dt='.$expectedStartDate.'&billable_metric_code=code';
    });
});

it('forwards the params and defaults the granularity when premium', function (): void {
    premiumDataApi(fn (): App\Services\BaseResult => dataApiUsagesCall($this->organization, [
        'time_granularity' => 'monthly',
        'additional_param' => 'value',
        'from_date' => '2024-01-01',
    ]));

    Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
        return $request->url() === 'https://data.lago.test/usages/'.$this->organization->id
            .'/?time_granularity=monthly&additional_param=value&from_date=2024-01-01';
    });
});

it('defaults the granularity to daily when premium without one', function (): void {
    premiumDataApi(fn (): App\Services\BaseResult => dataApiUsagesCall($this->organization));

    Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), '/?time_granularity=daily');
    });
});

// -- failure modes --------------------------------------------------------------

it('retries a transient error and succeeds', function (): void {
    Http::fake([
        'data.lago.test/*' => Http::sequence()
            ->push('transient error', 500)
            ->push(dataApiUsagesFixture(), 200),
    ]);

    $result = UsagesService::call(organization: $this->organization);

    expect($result->success())->toBeTrue()
        ->and($result->usages)->toHaveCount(3);

    Http::assertSentCount(2);
});

it('raises the http error for a non-success code', function (): void {
    Http::fake(['data.lago.test/*' => Http::response('unauthorized', 401)]);

    UsagesService::call(organization: $this->organization);
})->throws(LagoHttpError::class);

it('does not retry a non-transient status', function (): void {
    Http::fake(['data.lago.test/*' => Http::response('unauthorized', 401)]);

    try {
        UsagesService::call(organization: $this->organization);
    } catch (LagoHttpError) {
        // expected
    }

    Http::assertSentCount(1);
});

it('retries connection errors up to three attempts then rethrows', function (): void {
    $attempts = 0;

    Http::fake(function () use (&$attempts): void {
        $attempts++;

        throw new ConnectionException('connection refused');
    });

    try {
        UsagesService::call(organization: $this->organization);
        $this->fail('expected the connection error to rethrow');
    } catch (ConnectionException) {
        // expected — Rails' LagoHttpClient gives up after MAX_RETRIES_ATTEMPTS.
    }

    expect($attempts)->toBe(3);
});
