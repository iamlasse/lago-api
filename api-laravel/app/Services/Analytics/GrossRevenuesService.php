<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\BaseResult;
use App\Models\Analytics\GrossRevenue;

/**
 * Port of Rails' Analytics::GrossRevenuesService — NOT premium-gated
 * (unlike mrr / invoiced_usage / invoice_collection).
 */
class GrossRevenuesService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = $this->makeResult();

        $result->records = GrossRevenue::findAllBy(
            $this->organization->id,
            $this->filters,
        );

        return $result;
    }
}
