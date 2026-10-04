<?php

declare(strict_types=1);

namespace App\Services\DataApi;

use App\Services\BaseResult;

/**
 * Port of Rails' DataApi::MrrsService (app/services/data_api/mrrs_service.rb)
 * — premium-only passthrough GET of the organization's MRR time series from
 * the Lago Data API. Params are forwarded as-is.
 */
class MrrsService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult('mrrs');

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->mrrs = $this->httpClient()->get(headers: $this->headers(), params: $this->params);

        return $result;
    }

    protected function actionPath(): string
    {
        return "mrrs/{$this->organization->id}/";
    }
}
