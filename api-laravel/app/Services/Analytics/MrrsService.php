<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\BaseResult;
use App\Models\Analytics\Mrr;

/**
 * Port of Rails' Analytics::MrrsService — premium-gated (the REST
 * /analytics/mrr row is the ClickHouse analytics model, NOT
 * DataApi::MrrsService).
 */
class MrrsService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = $this->makeResult();

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->records = Mrr::findAllBy(
            $this->organization->id,
            $this->filters,
        );

        return $result;
    }
}
