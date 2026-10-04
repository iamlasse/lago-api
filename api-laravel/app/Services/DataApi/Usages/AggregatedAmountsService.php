<?php

declare(strict_types=1);

namespace App\Services\DataApi\Usages;

use App\Services\BaseResult;
use App\Services\DataApi\BaseService;

/**
 * Port of Rails' DataApi::Usages::AggregatedAmountsService
 * (app/services/data_api/usages/aggregated_amounts_service.rb) — premium-only
 * GET of aggregated usage amounts from the Lago Data API (GraphQL
 * dataApiUsages.aggregatedAmounts). Params are forwarded as-is.
 */
class AggregatedAmountsService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult('aggregated_amounts_usages');

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->aggregated_amounts_usages =
            $this->httpClient()->get(headers: $this->headers(), params: $this->params);

        return $result;
    }

    protected function actionPath(): string
    {
        return "usages/{$this->organization->id}/aggregated_amounts/";
    }
}
