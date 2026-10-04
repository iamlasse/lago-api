<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Organization;
use App\Services\BaseService as RootBaseService;

/**
 * Port of Rails' Analytics::BaseService (app/services/analytics/base_service.rb)
 * — `Result = BaseResult[:records]` over the analytics models
 * (App\Models\Analytics\* — raw SQL on the primary connection; see the
 * base model's docblock).
 */
abstract class BaseService extends RootBaseService
{
    public function __construct(
        protected readonly Organization $organization,
        protected readonly array $filters = [],
    ) {
        parent::__construct();
    }
}
