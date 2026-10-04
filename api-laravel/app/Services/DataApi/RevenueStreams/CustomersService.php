<?php

declare(strict_types=1);

namespace App\Services\DataApi\RevenueStreams;

use App\Services\BaseResult;
use App\Services\DataApi\BaseService;

/**
 * Port of Rails' DataApi::RevenueStreams::CustomersService
 * (app/services/data_api/revenue_streams/customers_service.rb) — premium-only
 * GET of the per-customer revenue streams breakdown from the Lago Data API
 * (GraphQL dataApiRevenueStreams.customers). Params are forwarded as-is.
 */
class CustomersService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult('data_revenue_streams_customers');

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->data_revenue_streams_customers =
            $this->httpClient()->get(headers: $this->headers(), params: $this->params);

        return $result;
    }

    protected function actionPath(): string
    {
        return "revenue_streams/{$this->organization->id}/customers/";
    }
}
