<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\BaseResult;
use App\Models\Analytics\InvoicedUsage;

/**
 * Port of Rails' Analytics::InvoicedUsagesService — `return
 * result.forbidden_failure! unless License.premium?`.
 */
class InvoicedUsagesService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = $this->makeResult();

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->records = InvoicedUsage::findAllBy(
            $this->organization->id,
            $this->filters,
        );

        return $result;
    }
}
