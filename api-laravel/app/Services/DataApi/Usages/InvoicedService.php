<?php

declare(strict_types=1);

namespace App\Services\DataApi\Usages;

use App\Services\BaseResult;
use App\Services\DataApi\BaseService;

/**
 * Port of Rails' DataApi::Usages::InvoicedService
 * (app/services/data_api/usages/invoiced_service.rb) — premium-only GET of
 * invoiced usage from the Lago Data API (GraphQL dataApiUsages.invoiced).
 * Params are forwarded as-is.
 */
class InvoicedService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult('invoiced_usages');

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->invoiced_usages = $this->httpClient()->get(headers: $this->headers(), params: $this->params);

        return $result;
    }

    protected function actionPath(): string
    {
        return "usages/{$this->organization->id}/invoiced/";
    }
}
