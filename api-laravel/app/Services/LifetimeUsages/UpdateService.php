<?php

declare(strict_types=1);

namespace App\Services\LifetimeUsages;

use App\Services\BaseResult;
use App\Models\LifetimeUsage;

/**
 * Port of Rails' LifetimeUsages::UpdateService
 * (app/services/lifetime_usages/update_service.rb) — the
 * PUT/PATCH /subscriptions/:external_id/lifetime_usage endpoint: reports an
 * externally tracked historical usage amount.
 */
class UpdateService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?LifetimeUsage $lifetimeUsage,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('lifetime_usage');

        if ($this->lifetimeUsage === null) {
            return $result->notFoundFailure('lifetime_usage');
        }

        $lifetimeUsage = $this->lifetimeUsage;

        $lifetimeUsage->historical_usage_amount_cents =
            (int) ($this->params['external_historical_usage_amount_cents'] ?? $lifetimeUsage->historical_usage_amount_cents);

        $lifetimeUsage->save();

        $result->lifetime_usage = $lifetimeUsage;

        return $result;
    }
}
