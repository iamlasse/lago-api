<?php

declare(strict_types=1);

namespace App\Services\DataApi\Mrrs;

use App\Services\BaseResult;
use App\Services\DataApi\BaseService;

/**
 * Port of Rails' DataApi::Mrrs::PlansService
 * (app/services/data_api/mrrs/plans_service.rb) — premium-only GET of the
 * per-plan MRR breakdown from the Lago Data API (GraphQL dataApiMrrs.plans).
 * Params are forwarded as-is.
 */
class PlansService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult('data_mrrs_plans');

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->data_mrrs_plans = $this->httpClient()->get(headers: $this->headers(), params: $this->params);

        return $result;
    }

    protected function actionPath(): string
    {
        return "mrrs/{$this->organization->id}/plans/";
    }
}
