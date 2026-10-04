<?php

declare(strict_types=1);

namespace App\Services\DataApi\RevenueStreams;

use App\Services\BaseResult;
use App\Services\DataApi\BaseService;

/**
 * Port of Rails' DataApi::RevenueStreams::PlansService
 * (app/services/data_api/revenue_streams/plans_service.rb) — premium-only GET
 * of the per-plan revenue streams breakdown from the Lago Data API
 * (GraphQL dataApiRevenueStreams.plans). Params are forwarded as-is.
 */
class PlansService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult('data_revenue_streams_plans');

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->data_revenue_streams_plans =
            $this->httpClient()->get(headers: $this->headers(), params: $this->params);

        return $result;
    }

    protected function actionPath(): string
    {
        return "revenue_streams/{$this->organization->id}/plans/";
    }
}
