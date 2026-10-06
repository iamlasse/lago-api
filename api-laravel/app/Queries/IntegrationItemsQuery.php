<?php

declare(strict_types=1);

namespace App\Queries;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\IntegrationItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' IntegrationItemsQuery (app/queries/integration_items_query.rb)
 * — the provider items synced for one integration, searched by external
 * name/id/account code.
 */
class IntegrationItemsQuery extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
        private readonly ?string $searchTerm = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_items');

        // Rails: IntegrationItem.joins(:integration).where(integration:
        // {organization_id: organization.id}).
        $items = IntegrationItem::query()
            ->join('integrations', 'integrations.id', '=', 'integration_items.integration_id')
            ->where('integrations.organization_id', $this->organization->id)
            ->select('integration_items.*');

        $searchTerm = $this->searchTerm !== null ? mb_trim($this->searchTerm) : '';

        if ($searchTerm !== '') {
            $items->where(function (Builder $query) use ($searchTerm): void {
                $like = "%{$searchTerm}%";

                $query->where('integration_items.external_name', 'ILIKE', $like)
                    ->orWhere('integration_items.external_id', 'ILIKE', $like)
                    ->orWhere('integration_items.external_account_code', 'ILIKE', $like);
            });
        }

        $integrationId = $this->filters['integration_id'] ?? null;
        if ($integrationId !== null && $integrationId !== '' && $integrationId !== []) {
            $items->where('integration_items.integration_id', $integrationId);
        }

        $itemType = $this->filters['item_type'] ?? null;
        if ($itemType !== null) {
            // Rails: enum :item_type, [:standard, :tax, :account] — the
            // wire enum name maps onto the stored integer position.
            $position = match ($itemType) {
                'standard' => 0,
                'tax' => 1,
                'account' => 2,
                default => null,
            };

            if ($position !== null) {
                $items->where('integration_items.item_type', $position);
            }
        }

        $result->integration_items = $this->paginate($items);

        return $result;
    }

    /**
     * Rails: paginate → order(external_name: :asc) → apply_consistent_ordering
     * (created_at DESC, id ASC on top).
     */
    private function paginate(Builder $query): LengthAwarePaginator
    {
        $page = max(1, (int) ($this->pagination['page'] ?? 1));
        $limit = max(1, (int) ($this->pagination['limit'] ?? \App\GraphQL\Support\Page::DEFAULT_LIMIT));

        return $query
            ->orderBy('integration_items.external_name')
            ->orderByDesc('integration_items.created_at')
            ->orderBy('integration_items.id')
            ->paginate(perPage: $limit, page: $page);
    }
}
