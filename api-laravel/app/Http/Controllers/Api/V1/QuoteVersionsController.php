<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\QuoteVersion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;
use App\Services\QuoteVersions\VoidService;
use App\Services\QuoteVersions\CloneService;
use App\Serializers\V1\QuoteVersionSerializer;
use App\Services\QuoteVersions\ApproveService;

/**
 * Port of Rails' Api::V1::QuoteVersionsController
 * (app/controllers/api/v1/quote_versions_controller.rb) — show plus the
 * approve / void / clone transitions.
 *
 * API-key scope bucket: ApiKey::RESOURCES has "quote" but no
 * "quote_version", so quote versions authorize against the "quote" scope
 * (handled by the shared auth middleware); this is intentionally distinct
 * from the not_found_error "quote_version" resource label.
 */
class QuoteVersionsController extends ApiController
{
    protected ?string $resourceName = 'quote';

    public function show(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $quoteVersion = $this->findQuoteVersion($request->route('id'));

        if ($quoteVersion === null) {
            throw new NotFoundException('quote_version');
        }

        return $this->renderQuoteVersion($quoteVersion);
    }

    public function approve(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        // A null quote_version is intentional: the service answers not_found.
        $quoteVersion = $this->findQuoteVersion($request->route('id'));

        $result = ApproveService::call(
            quoteVersion: $quoteVersion,
            expiresAt: $request->input('expires_at'),
        );

        if ($result->success()) {
            return $this->renderQuoteVersion($result->quote_version);
        }

        $this->renderErrorResponse($result);
    }

    public function void(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        // A null quote_version is intentional: the service answers not_found.
        $quoteVersion = $this->findQuoteVersion($request->route('id'));

        $result = VoidService::call(
            quoteVersion: $quoteVersion,
            reason: 'manual',
        );

        if ($result->success()) {
            return $this->renderQuoteVersion($result->quote_version);
        }

        $this->renderErrorResponse($result);
    }

    public function clone(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        // A null quote_version is intentional: the service answers not_found.
        $quoteVersion = $this->findQuoteVersion($request->route('id'));

        $result = CloneService::call(quoteVersion: $quoteVersion);

        if ($result->success()) {
            return $this->renderQuoteVersion($result->quote_version);
        }

        $this->renderErrorResponse($result);
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

    protected function findQuoteVersion(mixed $id): ?QuoteVersion
    {
        return QuoteVersion::query()
            ->where('organization_id', $this->currentOrganization()?->id)
            ->where('id', $id)
            ->first();
    }

    private function renderQuoteVersion(QuoteVersion $quoteVersion): JsonResponse
    {
        return $this->renderSerializerJson(
            (new QuoteVersionSerializer(
                $quoteVersion,
                ['root_name' => 'quote_version', 'includes' => ['content', 'billing_items']],
            ))->toJson()
        );
    }
}
