<?php

declare(strict_types=1);

namespace App\Queries\UsageMonitoring;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\UsageMonitoring\Alert;
use Illuminate\Database\Eloquent\Builder;

/**
 * Port of Rails' UsageMonitoring::AlertsQuery
 * (app/queries/usage_monitoring/alerts_query.rb) — the alert index endpoints.
 */
class AlertsQuery extends BaseService
{
    /** Kaminari's default per page, used when the limit param is nil. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('alerts');

        $alerts = Alert::query()->where('organization_id', $this->organization->id);

        if (($this->filters['subscription_external_id'] ?? null) !== null) {
            $alerts->where('subscription_external_id', $this->filters['subscription_external_id']);
        }

        if (($this->filters['wallet_id'] ?? null) !== null) {
            $alerts->where('wallet_id', $this->filters['wallet_id']);
        }

        $alerts = $alerts
            ->latest('created_at')
            ->orderBy('id');

        $result->alerts = $this->paginate($alerts);

        return $result;
    }

    private function paginate(Builder $query): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $page = max(1, (int) ($this->pagination['page'] ?? 1));
        $limit = (int) ($this->pagination['limit'] ?? self::DEFAULT_PER_PAGE);

        return $query->paginate(perPage: $limit, page: $page);
    }
}
