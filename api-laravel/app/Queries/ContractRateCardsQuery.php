<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ContractRateCard;
use App\Queries\Concerns\RateCardListFiltering;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' ContractRateCardsQuery
 * (app/queries/contract_rate_cards_query.rb).
 *
 * The REST controller passes contract_id / external_id only; the GraphQL
 * `contractAppliedRateCards` resolver additionally passes the shared catalog
 * list filters, the search term and the product_category ordering.
 */
class ContractRateCardsQuery extends BaseService
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
        $result = static::makeResult('contract_rate_cards');

        $contractRateCards = ContractRateCard::query()
            ->where('contract_rate_cards.organization_id', $this->organization->id)
            ->with(['contract', 'rateCard', 'ratePhases.rateOverride']);

        if (($this->filters['contract_id'] ?? null) !== null) {
            $contractRateCards->where('contract_rate_cards.contract_id', $this->filters['contract_id']);
        }

        if (($this->filters['external_id'] ?? null) !== null) {
            $contractRateCards->join('contracts', 'contracts.id', '=', 'contract_rate_cards.contract_id')
                ->where('contracts.external_id', $this->filters['external_id'])
                // The join's columns share names with the card's (id,
                // created_at, updated_at); without the explicit select the
                // contracts row clobbers the model attributes.
                ->select('contract_rate_cards.*');
        }

        // Rails: apply_rate_card_filters(phase_parent: :contract_rate_card_id).
        $contractRateCards = $this->applyRateCardFilters($contractRateCards, 'contract_rate_card_id');

        if ($this->order === 'product_category') {
            $contractRateCards = $this->orderByProductCategory($contractRateCards);

            // Rails: order_by_product_category(...).order(:effective_date, :id).
            $result->contract_rate_cards = $this->paginate(
                $contractRateCards
                    ->oldest('contract_rate_cards.effective_date')
                    ->orderBy('contract_rate_cards.id'),
            );
        } else {
            // Rails: same order as a contract's appliedRateCards —
            // apply_consistent_ordering with default effective_date asc.
            $result->contract_rate_cards = $this->paginate(
                $contractRateCards
                    ->oldest('contract_rate_cards.effective_date')
                    ->latest('contract_rate_cards.created_at')
                    ->orderBy('contract_rate_cards.id'),
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
