<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\GraphQL\Support\Page;
use App\Services\BaseService;
use App\Models\DunningCampaign;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' DunningCampaignsQuery (app/queries/dunning_campaigns_query.rb)
 * — org scope, name/code search_term OR-containment, name|code ordering
 * (name default), the legacy applied_to_organization filter (campaigns
 * attached — or not — to the default billing entity) and the
 * currency-threshold filter.
 */
class DunningCampaignsQuery extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly ?string $searchTerm = null,
        private readonly ?string $order = null,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('dunning_campaigns');

        /** @var Organization $organization */
        $organization = $this->organization;

        $campaigns = DunningCampaign::query()
            ->where('organization_id', $organization->id);

        $searchTerm = $this->searchTerm;

        if ($searchTerm !== null && $searchTerm !== '') {
            // Rails: ransack m: or, name_cont / code_cont.
            $campaigns->where(function ($query) use ($searchTerm): void {
                $query->where('name', 'ilike', "%{$searchTerm}%")
                    ->orWhere('code', 'ilike', "%{$searchTerm}%");
            });
        }

        $appliedToOrganization = $this->filters['applied_to_organization'] ?? null;

        if ($appliedToOrganization !== null) {
            $defaultBillingEntityId = $organization->defaultBillingEntity?->id;

            if ($appliedToOrganization) {
                $campaigns->whereExists(function ($sub) use ($defaultBillingEntityId): void {
                    $sub->selectRaw(1)
                        ->from('billing_entities')
                        ->whereColumn('billing_entities.applied_dunning_campaign_id', 'dunning_campaigns.id')
                        ->where('billing_entities.id', $defaultBillingEntityId);
                });
            } else {
                $campaigns->whereNotExists(function ($sub) use ($defaultBillingEntityId): void {
                    $sub->selectRaw(1)
                        ->from('billing_entities')
                        ->whereColumn('billing_entities.applied_dunning_campaign_id', 'dunning_campaigns.id')
                        ->where('billing_entities.id', $defaultBillingEntityId);
                });
            }
        }

        $currencies = $this->filters['currency'] ?? null;

        if (is_array($currencies) && $currencies !== []) {
            // Rails: with_currency_threshold — distinct campaigns carrying a
            // threshold in one of the currencies.
            $campaigns->whereExists(function ($sub) use ($currencies): void {
                $sub->selectRaw(1)
                    ->from('dunning_campaign_thresholds')
                    ->whereColumn('dunning_campaign_thresholds.dunning_campaign_id', 'dunning_campaigns.id')
                    ->whereIn('dunning_campaign_thresholds.currency', $currencies)
                    ->whereNull('dunning_campaign_thresholds.deleted_at');
            });
        }

        $result->dunning_campaigns = $this->paginate(
            $campaigns->orderBy($this->orderColumn(), 'asc'),
        );

        return $result;
    }

    /** Rails: ORDERS.include?(order) ? order : DEFAULT_ORDER ("name"). */
    private function orderColumn(): string
    {
        return in_array($this->order, DunningCampaign::ORDERS, true)
            ? $this->order
            : 'name';
    }

    private function paginate(\Illuminate\Database\Eloquent\Builder $scope): LengthAwarePaginator
    {
        [$page, $limit] = Page::normalizePageAndLimit(
            is_numeric($this->pagination['page'] ?? null) ? (int) $this->pagination['page'] : null,
            is_numeric($this->pagination['limit'] ?? null) ? (int) $this->pagination['limit'] : null,
        );

        return $scope->paginate(perPage: $limit, page: $page);
    }
}
