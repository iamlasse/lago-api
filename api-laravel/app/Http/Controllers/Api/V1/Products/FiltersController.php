<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Products;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\ProductFilter;
use Illuminate\Http\JsonResponse;
use App\Queries\ProductFiltersQuery;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Services\ProductFilters\CreateService;
use App\Services\ProductFilters\UpdateService;
use App\Serializers\V1\ProductFilterSerializer;
use App\Services\ProductFilters\DestroyService;
use App\Http\Controllers\Concerns\RequiresProductCatalog;

/**
 * Port of Rails' Api::V2::Products::FiltersController
 * (app/controllers/api/v2/products/filters_controller.rb).
 */
class FiltersController extends ApiController
{
    use Pagination;
    use RequiresProductCatalog;

    protected ?string $resourceName = 'product';

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $product = $this->findProduct($request);

        $result = ProductFiltersQuery::call(
            organization: $this->currentOrganization(),
            searchTerm: $request->query('search_term'),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: ['product_id' => $product->id],
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->product_filters,
                ProductFilterSerializer::class,
                [
                    'collection_name' => 'filters',
                    'meta' => $this->paginationMetadata($result->product_filters),
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $this->findProduct($request);
        $productFilter = $this->findProductFilter($request);

        return $this->renderFilter($productFilter);
    }

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $product = $this->findProduct($request);

        $result = CreateService::call(
            product: $product,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            return $this->renderFilter($result->product_filter);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $this->findProduct($request);
        $productFilter = $this->findProductFilter($request);

        $result = UpdateService::call(
            productFilter: $productFilter,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            return $this->renderFilter($result->product_filter);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $this->findProduct($request);
        $productFilter = $this->findProductFilter($request);

        // The destroy serializer echoes the values the service discarded.
        $values = $productFilter->values->map(fn ($value): object => (object) [
            'billableMetricFilter' => $value->billableMetricFilter,
            'value' => $value->value,
        ])->all();

        $result = DestroyService::call(productFilter: $productFilter);

        if ($result->success()) {
            return $this->renderFilter($result->product_filter, $values);
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function findProduct(Request $request): Product
    {
        $product = $this->currentOrganization()->products()
            ->where('code', $request->route('product_code'))
            ->first();

        if ($product === null) {
            throw new \App\Exceptions\Api\NotFoundException('product');
        }

        return $product;
    }

    private function findProductFilter(Request $request): ProductFilter
    {
        $productFilter = ProductFilter::query()
            ->where('product_id', $this->currentOrganization()->products()->where('code', $request->route('product_code'))->value('id') ?? '')
            ->where('code', $request->route('code'))
            ->first();

        if ($productFilter === null) {
            throw new \App\Exceptions\Api\NotFoundException('product_filter');
        }

        return $productFilter;
    }

    /**
     * @param  list<array{billableMetricFilter: object, value: ?string}>|null  $values
     */
    private function renderFilter(ProductFilter $productFilter, ?array $values = null): JsonResponse
    {
        return $this->renderSerializerJson((new ProductFilterSerializer(
            $productFilter,
            ['root_name' => 'filter', 'values' => $values],
        ))->toJson());
    }

    /**
     * Port of `params.require(:filter).permit(..., values: %i[key value])`.
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        $filter = $this->requireParams($request, 'filter');

        return $this->permitParams($filter, [
            'name',
            'code',
            'description',
            'invoice_display_name',
            'values' => [['key', 'value']],
        ]);
    }
}
