<?php

declare(strict_types=1);

namespace App\Queries;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\IntegrationCollectionMappings\BaseCollectionMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' IntegrationCollectionMappingsQuery (app/queries/
 * integration_collection_mappings_query.rb) — the org-scoped collection
 * mappings, ordered created_at desc / id asc, optionally filtered to one
 * integration.
 */
class IntegrationCollectionMappingsQuery extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_collection_mappings');

        // Rails: base_scope — joins(:integration).where(integration:
        // {organization: organization}).
        $mappings = BaseCollectionMapping::query()
            ->join('integrations', 'integrations.id', '=', 'integration_collection_mappings.integration_id')
            ->where('integrations.organization_id', $this->organization->id)
            ->select('integration_collection_mappings.*');

        // Rails: apply_consistent_ordering — created_at desc, id asc.
        $mappings->orderByDesc('integration_collection_mappings.created_at')
            ->orderBy('integration_collection_mappings.id');

        $integrationId = $this->filters['integration_id'] ?? null;
        if ($integrationId !== null) {
            $mappings->where('integration_collection_mappings.integration_id', $integrationId);
        }

        [$page, $limit] = \App\GraphQL\Support\Page::normalizePageAndLimit(
            $this->pagination['page'] ?? null,
            $this->pagination['limit'] ?? null,
        );

        /** @var LengthAwarePaginator $paginated */
        $paginated = $mappings->paginate($limit, ['*'], 'page', $page);

        $result->integration_collection_mappings = $paginated;

        return $result;
    }
}
