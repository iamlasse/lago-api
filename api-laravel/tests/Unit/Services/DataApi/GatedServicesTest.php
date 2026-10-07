<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Http\Client\LagoHttpError;
use Illuminate\Support\Facades\Http;
use App\Services\DataApi\MrrsService;
use App\Services\DataApi\PrepaidCreditsService;
use App\Services\DataApi\RevenueStreamsService;
use App\Services\DataApi\Usages\InvoicedService;
use App\Services\DataApi\Usages\AggregatedAmountsService;
use App\Services\DataApi\Mrrs\PlansService as MrrsPlansService;
use App\Services\DataApi\RevenueStreams\PlansService as RevenueStreamsPlansService;
use App\Services\DataApi\RevenueStreams\CustomersService as RevenueStreamsCustomersService;

/**
 * Port of the Rails spec/services/data_api specs for the premium-gated
 * passthrough services (mrrs_service_spec.rb, revenue_streams_service_spec.rb,
 * prepaid_credits_service_spec.rb and the nested plans/customers/invoiced/
 * aggregated_amounts specs): the non-premium forbidden failure and the
 * premium passthrough with the expected Data API path and bearer header.
 */
beforeEach(function (): void {
    config([
        'lago.data_api_url' => 'https://data.lago.test',
        'lago.data_api_bearer_token' => 'data-api-bearer',
    ]);

    $this->organization = Organization::factory()->create();
});

it('fails forbidden when the license is not premium', function (string $serviceClass): void {
    Http::fake();

    $result = $serviceClass::call(organization: $this->organization);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->not->toBeNull()
        ->and($result->getError()->code)->toBe('feature_unavailable');

    Http::assertNothingSent();
})->with([
    'mrrs' => [MrrsService::class],
    'revenue streams' => [RevenueStreamsService::class],
    'prepaid credits' => [PrepaidCreditsService::class],
    'mrrs plans' => [MrrsPlansService::class],
    'revenue streams customers' => [RevenueStreamsCustomersService::class],
    'revenue streams plans' => [RevenueStreamsPlansService::class],
    'aggregated amounts' => [AggregatedAmountsService::class],
    'invoiced usages' => [InvoicedService::class],
]);

it('forwards the params to the expected data api path when premium', function (string $serviceClass, string $path): void {
    config(['lago.license' => 'premium-license-token']);

    try {
        Http::fake(['data.lago.test/*' => Http::response(['data' => true], 200)]);

        $result = $serviceClass::call(organization: $this->organization, params: ['currency' => 'EUR']);

        expect($result->success())->toBeTrue();
    } finally {
        config(['lago.license' => null]);
    }

    $expectedUrl = 'https://data.lago.test/'.str_replace('{id}', $this->organization->id, $path)
        .'?currency=EUR';

    Http::assertSent(fn(Illuminate\Http\Client\Request $request): bool => $request->method() === 'GET'
        && $request->url() === $expectedUrl
        && $request->hasHeader('Authorization', 'Bearer data-api-bearer'));
})->with([
    'mrrs' => [MrrsService::class, 'mrrs/{id}/'],
    'revenue streams' => [RevenueStreamsService::class, 'revenue_streams/{id}/'],
    'prepaid credits' => [PrepaidCreditsService::class, 'prepaid_credits/{id}/'],
    'mrrs plans' => [MrrsPlansService::class, 'mrrs/{id}/plans/'],
    'revenue streams customers' => [RevenueStreamsCustomersService::class, 'revenue_streams/{id}/customers/'],
    'revenue streams plans' => [RevenueStreamsPlansService::class, 'revenue_streams/{id}/plans/'],
    'aggregated amounts' => [AggregatedAmountsService::class, 'usages/{id}/aggregated_amounts/'],
    'invoiced usages' => [InvoicedService::class, 'usages/{id}/invoiced/'],
]);

it('raises the http error through the gated services', function (): void {
    config(['lago.license' => 'premium-license-token']);

    try {
        Http::fake(['data.lago.test/*' => Http::response('boom', 502)]);

        MrrsService::call(organization: $this->organization);
    } finally {
        config(['lago.license' => null]);
    }
})->throws(LagoHttpError::class);
