<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Quote;
use App\Queries\QuotesQuery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Serializers\V1\QuoteSerializer;
use App\Exceptions\Api\NotFoundException;
use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;

/**
 * Port of Rails' Api::V1::QuotesController
 * (app/controllers/api/v1/quotes_controller.rb) — quotes are keyed by uuid
 * id, read-only over REST (creation/updates are GraphQL-only), and every
 * action gates on the order_forms feature flag (Rails:
 * ensure_feature_flag! → 403 feature_unavailable).
 */
class QuotesController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'quote';

    public function index(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $result = QuotesQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: $this->indexFilters($request),
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->quotes,
                    QuoteSerializer::class,
                    [
                        'collection_name' => 'quotes',
                        'meta' => $this->paginationMetadata($result->quotes),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $quote = Quote::query()
            ->where('organization_id', $this->currentOrganization()?->id)
            ->where('id', $request->route('id'))
            ->first();

        if ($quote === null) {
            throw new NotFoundException('quote');
        }

        return $this->renderSerializerJson(
            (new QuoteSerializer(
                $quote,
                ['root_name' => 'quote', 'includes' => ['owners']],
            ))->toJson()
        );
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Rails: ensure_feature_flag! — forbidden_error(code:
     * "feature_unavailable") unless the order_forms flag is on.
     */
    protected function ensureOrderFormsFeature(): void
    {
        $organization = $this->currentOrganization();

        if ($organization === null
            || ! in_array('order_forms', (array) ($organization->feature_flags ?? []), true)) {
            throw new ForbiddenException('feature_unavailable');
        }
    }

    /**
     * Rails: `index_filters` — the quotes index query params, verbatim.
     * Array.wrap(params[:x]).presence — a scalar wraps into a list.
     *
     * @return array<string, mixed>
     */
    protected function indexFilters(Request $request): array
    {
        return [
            'customers' => $this->wrapFilter($request, 'customer_id'),
            'external_customer_ids' => $this->wrapFilter($request, 'external_customer_id'),
            'numbers' => $this->wrapFilter($request, 'number'),
            'statuses' => $this->wrapFilter($request, 'status'),
            'owners' => $this->wrapFilter($request, 'owner_id'),
            'order_types' => $this->wrapFilter($request, 'order_type'),
            'from_date' => $request->query('from_date'),
            'to_date' => $request->query('to_date'),
        ];
    }

    /**
     * Rails: Array.wrap — a scalar becomes a one-element list, an array stays.
     *
     * @return list<string>|null
     */
    protected function wrapFilter(Request $request, string $key): ?array
    {
        $value = $request->query($key);

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return array_map(strval(...), is_array($value) ? $value : [$value]);
    }
}
