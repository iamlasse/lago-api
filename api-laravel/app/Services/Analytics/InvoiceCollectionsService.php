<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\BaseResult;
use App\Models\Analytics\InvoiceCollection;

/**
 * Port of Rails' Analytics::InvoiceCollectionsService — premium-gated.
 */
class InvoiceCollectionsService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = $this->makeResult();

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        $result->records = InvoiceCollection::findAllBy(
            $this->organization->id,
            $this->filters,
        );

        return $result;
    }
}
