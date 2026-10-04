<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\RateCard;
use Illuminate\Http\Request;
use App\Models\ProductFilter;
use App\Queries\RateCardsQuery;
use Illuminate\Http\JsonResponse;
use App\Services\RateCards\CreateService;
use App\Services\RateCards\UpdateService;
use App\Serializers\V1\RateCardSerializer;
use App\Services\RateCards\DestroyService;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Http\Controllers\Concerns\RequiresProductCatalog;

/**
 * Port of Rails' Api::V2::RateCardsController
 * (app/controllers/api/v2/rate_cards_controller.rb).
 */
class RateCardsController extends ApiController
{
    use Pagination;
    use RequiresProductCatalog;

    protected ?string $resourceName = 'rate_card';

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $createParams = $this->createParams($request);

        if (($createParams['product_filter_code'] ?? null) !== null && $this->product($createParams) !== null
            && $this->productFilter($createParams) === null) {
            throw new \App\Exceptions\Api\NotFoundException('product_filter');
        }

        $result = CreateService::call(
            product: $this->product($createParams),
            params: $this->serviceParams($createParams),
        );

        if ($result->success()) {
            return $this->renderRateCard($result->rate_card);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $result = UpdateService::call(
            rateCard: $this->findRateCard($request),
            params: $this->updateParams($request),
        );

        if ($result->success()) {
            return $this->renderRateCard($result->rate_card);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $result = DestroyService::call(rateCard: $this->findRateCard($request));

        if ($result->success()) {
            return $this->renderRateCard($result->rate_card);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $rateCard = $this->findRateCard($request, fail: false);

        if ($rateCard === null) {
            throw new \App\Exceptions\Api\NotFoundException('rate_card');
        }

        return $this->renderRateCard($rateCard);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $result = RateCardsQuery::call(
            organization: $this->currentOrganization(),
            searchTerm: $request->query('search_term'),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: [
                'product_ids' => array_values(array_filter((array) $request->query('product_id'))) ?: null,
                'product_filter_ids' => array_values(array_filter((array) $request->query('product_filter_id'))) ?: null,
                'code' => $request->query('code'),
                'product_code' => $request->query('product_code'),
                'product_filter_code' => $request->query('product_filter_code'),
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->rate_cards,
                RateCardSerializer::class,
                [
                    'collection_name' => 'rate_cards',
                    'meta' => $this->paginationMetadata($result->rate_cards),
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function renderRateCard(RateCard $rateCard): JsonResponse
    {
        return $this->renderSerializerJson((new RateCardSerializer(
            $rateCard,
            ['root_name' => 'rate_card', 'includes' => ['active_rate', 'taxes']],
        ))->toJson());
    }

    private function findRateCard(Request $request, bool $fail = true): ?RateCard
    {
        $rateCard = $this->currentOrganization()->rateCards()
            ->where('code', $request->route('rate_card_code'))
            ->first();

        if ($rateCard === null && $fail) {
            throw new \App\Exceptions\Api\NotFoundException('rate_card');
        }

        return $rateCard;
    }

    private function product(array $createParams): ?Product
    {
        return $this->currentOrganization()->products()
            ->where('code', $createParams['product_code'] ?? null)
            ->first();
    }

    private function productFilter(array $createParams): ?ProductFilter
    {
        $product = $this->product($createParams);

        if ($product === null || ($createParams['product_filter_code'] ?? null) === null) {
            return null;
        }

        return $product->filters()->where('code', $createParams['product_filter_code'])->first();
    }

    private function serviceParams(array $createParams): array
    {
        $product = $this->product($createParams);

        unset($createParams['product_code'], $createParams['product_filter_code']);

        $createParams['product_filter_id'] = $this->productFilter($createParams)?->id;

        return $createParams;
    }

    /**
     * Port of `params.require(:rate_card).permit(...)` — nested rates carry
     * their rate_properties hash unconstrained (Rails `rate_properties: {}`).
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        $rateCard = $this->requireParams($request, 'rate_card');

        return $this->permitParams($rateCard, [
            'product_code',
            'product_filter_code',
            'name',
            'code',
            'description',
            'currency',
            'billing_timing',
            'proration',
            'display_on_invoice',
            'regroup_paid_fees',
            'applied_pricing_unit_code',
            'tax_codes' => [],
            'rates' => [[
                'code',
                'effective_from',
                'rate_model',
                'min_amount_cents',
                'billing_interval_count',
                'billing_interval_unit',
                'applied_pricing_unit_conversion_rate',
                'rate_properties' => '*',
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    private function updateParams(Request $request): array
    {
        $rateCard = $this->requireParams($request, 'rate_card');

        return $this->permitParams($rateCard, [
            'name',
            'code',
            'description',
            'currency',
            'billing_timing',
            'proration',
            'display_on_invoice',
            'regroup_paid_fees',
            'applied_pricing_unit_code',
            'tax_codes' => [],
        ]);
    }
}
