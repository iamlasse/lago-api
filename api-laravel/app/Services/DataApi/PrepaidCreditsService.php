<?php

declare(strict_types=1);

namespace App\Services\DataApi;

use App\Services\BaseResult;

/**
 * Port of Rails' DataApi::PrepaidCreditsService
 * (app/services/data_api/prepaid_credits_service.rb) — premium-only
 * passthrough GET of the organization's prepaid credits series from the Lago
 * Data API. Params are forwarded as-is.
 */
class PrepaidCreditsService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult('prepaid_credits');

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->prepaid_credits = $this->httpClient()->get(headers: $this->headers(), params: $this->params);

        return $result;
    }

    protected function actionPath(): string
    {
        return "prepaid_credits/{$this->organization->id}/";
    }
}
