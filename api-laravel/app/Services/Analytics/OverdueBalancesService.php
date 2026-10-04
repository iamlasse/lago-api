<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\BaseResult;
use App\Models\Analytics\OverdueBalance;

/**
 * Port of Rails' Analytics::OverdueBalancesService — NOT premium-gated.
 */
class OverdueBalancesService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = $this->makeResult();

        $result->records = OverdueBalance::findAllBy(
            $this->organization->id,
            $this->filters,
        );

        return $result;
    }
}
