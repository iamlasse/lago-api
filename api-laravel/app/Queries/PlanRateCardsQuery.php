<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\PlanRateCard;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Queries\Concerns\RateCardListFiltering;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' PlanRateCardsQuery (app/queries/plan_rate_cards_query.rb).
 *
 * The REST controller passes plan_code only; the GraphQL `planAppliedRateCards`
 * resolver additionally passes plan_id, the shared catalog list filters, the
 * search term and the product_category ordering.
 */
class PlanRateCardsQuery extends BaseService
{
    use RateCardListFiltering;

    private const DEFAULT_PER_PAGE = 100;

    public function __construct(
        private readonly Organization $organization,
        private readonly ?string $searchTerm = null,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
        private readonly ?string $order = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('plan_rate_cards');

        $planRateCards = PlanRateCard::query()
            ->where('plan_rate_cards.organization_id', $this->organization->id)
            // Rails: includes(:catalog_plan, :rate_card, :rate_phases).
            ->with(['catalogPlan', 'rateCard', 'ratePhases']);

        if (($this->filters['plan_code'] ?? null) !== null) {
            $planRateCards->join('catalog_plans', 'catalog_plans.id', '=', 'plan_rate_cards.catalog_plan_id')
                ->where('catalog_plans.code', $this->filters['plan_code'])
                ->select('plan_rate_cards.*');
        }

        // Rails: with_plan.
        if (($this->filters['plan_id'] ?? null) !== null) {
            $planRateCards->where('plan_rate_cards.catalog_plan_id', $this->filters['plan_id']);
        }

        // Rails: apply_rate_card_filters(phase_parent: :plan_rate_card_id).
        $planRateCards = $this->applyRateCardFilters($planRateCards, 'plan_rate_card_id');

        // Rails: order :product_category (the resolvers' default) groups the
        // cards the way the catalog groups them on screen, then by id.
        if ($this->order === 'product_category') {
            $planRateCards = $this->orderByProductCategory($planRateCards);

            $result->plan_rate_cards = $this->paginate(
                $planRateCards->orderBy('plan_rate_cards.id'),
            );
        } else {
            // Rails: paginate + apply_consistent_ordering.
            $result->plan_rate_cards = $this->paginate(
                $planRateCards->orderByDesc('plan_rate_cards.created_at')->orderBy('plan_rate_cards.id'),
            );
        }

        return $result;
    }

    private function paginate(\Illuminate\Database\Eloquent\Builder $scope): LengthAwarePaginator
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
