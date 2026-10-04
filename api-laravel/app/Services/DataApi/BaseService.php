<?php

declare(strict_types=1);

namespace App\Services\DataApi;

use App\Models\Organization;
use App\Http\Client\LagoHttpClient;
use App\Services\BaseService as RootBaseService;

/**
 * Port of Rails' DataApi::BaseService (app/services/data_api/base_service.rb)
 * — plain HTTP GETs against the Lago Data API (LAGO_DATA_API_URL) with the
 * LAGO_DATA_API_BEARER_TOKEN bearer. Rails builds the client with
 * `retry_on_transient_errors: true`, which the ported LagoHttpClient `get()`
 * honors (connection errors and 500/502/503/504 retried up to 3 attempts).
 *
 * Rails lets LagoHttpClient::HttpError (non-success status, connection
 * failure) propagate out of the services unrescued — the request path has no
 * rescue_from for it and renders a 500. The port keeps that behavior: nothing
 * here wraps the client's failures.
 */
abstract class BaseService extends RootBaseService
{
    /**
     * @param  array<string, mixed>  $params  port of Rails' `**params`
     */
    public function __construct(
        protected readonly Organization $organization,
        protected readonly array $params = [],
    ) {
        parent::__construct();
    }

    /**
     * Rails: `action_path` — the Data API path under the base URL (note the
     * trailing slash, kept exactly).
     */
    abstract protected function actionPath(): string;

    /** Rails: `http_client` — `LagoHttpClient::Client.new(endpoint_url, retry_on_transient_errors: true)`. */
    protected function httpClient(): LagoHttpClient
    {
        return new LagoHttpClient($this->endpointUrl(), retryOnTransientErrors: true);
    }

    /** Rails: `headers` — `Authorization: Bearer #{ENV["LAGO_DATA_API_BEARER_TOKEN"]}`. */
    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.(string) config('lago.data_api_bearer_token'),
        ];
    }

    /** Rails: `endpoint_url` — `#{ENV["LAGO_DATA_API_URL"]}/#{action_path}`. */
    protected function endpointUrl(): string
    {
        return mb_rtrim((string) config('lago.data_api_url'), '/').'/'.$this->actionPath();
    }
}
