<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Enums\ProductType;
use Illuminate\Http\Request;
use App\Queries\ProductsQuery;
use Illuminate\Http\JsonResponse;
use App\Services\Products\CreateService;
use App\Services\Products\UpdateService;
use App\Serializers\V1\ProductSerializer;
use App\Services\Products\DestroyService;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Http\Controllers\Concerns\RequiresProductCatalog;

/**
 * Port of Rails' Api::V2::ProductsController
 * (app/controllers/api/v2/products_controller.rb).
 */
class ProductsController extends ApiController
{
    use Pagination;
    use RequiresProductCatalog;

    /** Rails: ERROR_FIELDS — model attribute to REST param naming. */
    private const ERROR_FIELDS = [
        'billable_metric' => 'billable_metric_code',
        'product_category' => 'product_category_code',
    ];

    protected ?string $resourceName = 'product';

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $inputParams = $this->inputParams($request);

        $productCategory = $this->productCategory($inputParams);

        if (($inputParams['product_category_code'] ?? null) !== null && $productCategory === null) {
            throw new \App\Exceptions\Api\NotFoundException('product_category');
        }

        $billableMetric = $this->billableMetric($inputParams);

        if (array_key_exists('billable_metric_code', $inputParams) && $billableMetric === null) {
            throw new \App\Exceptions\Api\NotFoundException('billable_metrics');
        }

        $serviceParams = $inputParams;
        unset($serviceParams['product_category_code'], $serviceParams['billable_metric_code']);
        $serviceParams['product_category_id'] = $productCategory?->id;
        $serviceParams['billable_metric_id'] = $billableMetric?->id;

        $result = CreateService::call(
            organization: $this->currentOrganization(),
            params: $serviceParams,
        );

        if ($result->success()) {
            return $this->renderProduct($result->product);
        }

        $this->renderItemError($result);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $product = $this->currentOrganization()->products()
            ->where('code', $request->route('product_code'))
            ->first();

        $updateParams = $this->updateParams($request);

        if (($updateParams['product_category_code'] ?? null) !== null
            && $this->updatedProductCategory($updateParams) === null) {
            throw new \App\Exceptions\Api\NotFoundException('product_category');
        }

        $serviceParams = $updateParams;
        unset($serviceParams['product_category_code']);

        // A blank code detaches the product from its product_category.
        if (array_key_exists('product_category_code', $updateParams)) {
            $category = $this->updatedProductCategory($updateParams);
            $serviceParams['product_category_id'] = $category?->id;
        }

        $result = UpdateService::call(product: $product, params: $serviceParams);

        if ($result->success()) {
            return $this->renderProduct($result->product);
        }

        $this->renderItemError($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $product = $this->currentOrganization()->products()
            ->where('code', $request->route('product_code'))
            ->first();

        $result = DestroyService::call(product: $product);

        if ($result->success()) {
            return $this->renderProduct($result->product);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $product = $this->currentOrganization()->products()
            ->where('code', $request->route('product_code'))
            ->first();

        if ($product === null) {
            throw new \App\Exceptions\Api\NotFoundException('product');
        }

        return $this->renderProduct($product);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $categoryCodes = array_values(array_filter((array) $request->query('product_category_code'), fn ($v) => $v !== null && $v !== ''));
        $indexCategories = $this->currentOrganization()->productCategories()
            ->whereIn('code', $categoryCodes)
            ->get();

        if ($categoryCodes !== [] && $indexCategories->count() !== count($categoryCodes)) {
            throw new \App\Exceptions\Api\NotFoundException('product_category');
        }

        // The column is a PG enum: an unknown value would fail the SQL cast.
        $productType = $request->query('product_type');
        $validTypes = array_map(fn (ProductType $type): string => $type->value, ProductType::cases());

        if ($productType !== null && ! in_array($productType, $validTypes, true)) {
            throw new \App\Exceptions\Api\ValidationException(['product_type' => ['value_is_invalid']]);
        }

        $result = ProductsQuery::call(
            organization: $this->currentOrganization(),
            searchTerm: $request->query('search_term'),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: [
                'product_category_ids' => $indexCategories->pluck('id')->all() ?: null,
                'without_product_category' => filter_var($request->query('without_product_category'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
                'product_type' => $productType,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                // Preloaded so filters_count reads the loaded association.
                $result->products,
                ProductSerializer::class,
                [
                    'collection_name' => 'products',
                    'meta' => $this->paginationMetadata($result->products),
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function renderProduct(Product $product): JsonResponse
    {
        return $this->renderSerializerJson((new ProductSerializer($product, ['root_name' => 'product']))->toJson());
    }

    /** Rails: `render_item_error` — maps validation fields, re-renders others. */
    private function renderItemError($result): never
    {
        $error = $result->getError();

        if ($error !== null && $error::class === \App\Services\Failures\ValidationFailure::class) {
            $messages = (array) $error->messages;
            $remapped = [];

            foreach ($messages as $key => $codes) {
                $remapped[self::ERROR_FIELDS[(string) $key] ?? $key] = $codes;
            }

            throw new \App\Exceptions\Api\ValidationException($remapped);
        }

        $this->renderErrorResponse($result);
    }

    private function productCategory(array $inputParams): ?object
    {
        if (($inputParams['product_category_code'] ?? null) === null) {
            return null;
        }

        return $this->currentOrganization()->productCategories()
            ->where('code', $inputParams['product_category_code'])
            ->first();
    }

    private function billableMetric(array $inputParams): ?object
    {
        if (($inputParams['billable_metric_code'] ?? null) === null) {
            return null;
        }

        return $this->currentOrganization()->billableMetrics()
            ->where('code', $inputParams['billable_metric_code'])
            ->first();
    }

    private function updatedProductCategory(array $updateParams): ?object
    {
        return $this->currentOrganization()->productCategories()
            ->where('code', $updateParams['product_category_code'])
            ->first();
    }

    /**
     * Port of `params.require(:product).permit(...)` — the contract,
     * verbatim.
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        $product = $this->requireParams($request, 'product');

        return $this->permitParams($product, [
            'name',
            'code',
            'description',
            'invoice_display_name',
            'product_type',
            'product_category_code',
            'billable_metric_code',
        ]);
    }

    /**
     * Port of the update `permit` — create-only params dropped.
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request): array
    {
        $product = $this->requireParams($request, 'product');

        return $this->permitParams($product, [
            'name',
            'code',
            'description',
            'invoice_display_name',
            'product_category_code',
        ]);
    }
}
