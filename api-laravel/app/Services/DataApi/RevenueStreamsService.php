<?php

declare(strict_types=1);

namespace App\Services\DataApi;

use App\Services\BaseResult;

/**
 * Port of Rails' DataApi::RevenueStreamsService
 * (app/services/data_api/revenue_streams_service.rb) — premium-only
 * passthrough GET of the organization's revenue streams from the Lago Data
 * API. Params are forwarded as-is.
 */
class RevenueStreamsService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult('revenue_streams');

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->revenue_streams = $this->httpClient()->get(headers: $this->headers(), params: $this->params);

        return $result;
    }

    protected function actionPath(): string
    {
        return "revenue_streams/{$this->organization->id}/";
    }
}
