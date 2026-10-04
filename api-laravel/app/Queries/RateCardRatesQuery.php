<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Models\RateCardRate;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' RateCardRatesQuery (app/queries/rate_card_rates_query.rb)
 * — the newest rates first (effective_from desc), no search.
 */
class RateCardRatesQuery extends BaseService
{
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
        $result = static::makeResult('rate_card_rates');

        // Rails: RateCardRate.where(organization:).includes(rate_card: :rates)
        // — the card's rates are preloaded so each rate's status derives from
        // the loaded timeline instead of one query per row.
        $rates = RateCardRate::query()
            ->where('rate_card_rates.organization_id', $this->organization->id)
            ->with(['rateCard.rates']);

        if (($this->filters['rate_card_id'] ?? null) !== null) {
            $rates->where('rate_card_rates.rate_card_id', $this->filters['rate_card_id']);
        }

        $rates = $this->paginate($rates);

        // Rails: .order(effective_from: :desc) AFTER pagination.
        $result->rate_card_rates = $rates->setCollection(
            $rates->getCollection()->sortByDesc('effective_from')->values(),
        );

        return $result;
    }

    private function paginate(\Illuminate\Database\Eloquent\Builder $scope): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $pageParam = $this->pagination['page'] ?? null;
        $limitParam = $this->pagination['limit'] ?? null;

        $page = is_numeric((string) $pageParam) && (string) $pageParam !== '' ? max(1, (int) $pageParam) : 1;

        $perPage = self::DEFAULT_PER_PAGE;
        if ($limitParam !== null && $limitParam !== '') {
            $perPage = max(1, (int) $limitParam);
        }

        return $scope->paginate($perPage, ['*'], 'page', $page);
    }
}
