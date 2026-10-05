<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Quotes;

use App\Models\Quote;
use App\Models\QuoteVersion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\QuoteVersionSerializer;

/**
 * Port of Rails' Api::V1::Quotes::VersionsController
 * (app/controllers/api/v1/quotes/versions_controller.rb) — the paginated
 * version history of one quote, newest first.
 */
class VersionsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'quote';

    public function index(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $quote = Quote::query()
            ->where('organization_id', $this->currentOrganization()?->id)
            ->where('id', $request->route('quote_id'))
            ->first();

        if ($quote === null) {
            throw new NotFoundException('quote');
        }

        // Each serialized version resolves its billing entity, falling back
        // to the customer's own (Rails preloads :billing_entity).
        $versions = QuoteVersion::query()
            ->where('quote_versions.quote_id', $quote->id)
            ->orderByDesc('sequential_id')
            ->paginate(
                perPage: is_numeric($request->query('per_page')) ? (int) $request->query('per_page') : self::PER_PAGE,
                page: is_numeric($request->query('page')) ? (int) $request->query('page') : null,
            );

        return $this->renderSerializerJson(
            (new CollectionSerializer(
                $versions,
                QuoteVersionSerializer::class,
                [
                    'collection_name' => 'quote_versions',
                    'meta' => $this->paginationMetadata($versions),
                ],
            ))->toJson()
        );
    }

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
}
