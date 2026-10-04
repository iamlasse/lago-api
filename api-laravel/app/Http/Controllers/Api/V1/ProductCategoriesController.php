<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use App\Queries\ProductCategoriesQuery;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\ProductCategorySerializer;
use App\Services\ProductCategories\CreateService;
use App\Services\ProductCategories\UpdateService;
use App\Services\ProductCategories\DestroyService;
use App\Http\Controllers\Concerns\RequiresProductCatalog;

/**
 * Port of Rails' Api::V2::ProductCategoriesController
 * (app/controllers/api/v2/product_categories_controller.rb).
 */
class ProductCategoriesController extends ApiController
{
    use Pagination;
    use RequiresProductCatalog;

    protected ?string $resourceName = 'product_category';

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $result = CreateService::call(
            organization: $this->currentOrganization(),
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            return $this->renderProductCategory($result->product_category);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $productCategory = $this->currentOrganization()->productCategories()
            ->where('code', $request->route('code'))
            ->first();

        $result = UpdateService::call(
            productCategory: $productCategory,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            return $this->renderProductCategory($result->product_category);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $productCategory = $this->currentOrganization()->productCategories()
            ->where('code', $request->route('code'))
            ->first();

        $result = DestroyService::call(productCategory: $productCategory);

        if ($result->success()) {
            return $this->renderProductCategory($result->product_category);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $productCategory = $this->currentOrganization()->productCategories()
            ->where('code', $request->route('code'))
            ->first();

        if ($productCategory === null) {
            throw new \App\Exceptions\Api\NotFoundException('product_category');
        }

        return $this->renderProductCategory($productCategory);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $result = ProductCategoriesQuery::call(
            organization: $this->currentOrganization(),
            searchTerm: $request->query('search_term'),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                // Preloaded so products_count reads the loaded association.
                $result->product_categories,
                ProductCategorySerializer::class,
                [
                    'collection_name' => 'product_categories',
                    'meta' => $this->paginationMetadata($result->product_categories),
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function renderProductCategory(ProductCategory $productCategory): JsonResponse
    {
        return $this->renderSerializerJson(
            (new ProductCategorySerializer($productCategory, ['root_name' => 'product_category']))->toJson(),
        );
    }

    /**
     * Port of `params.require(:product_category).permit(...)`.
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        $productCategory = $this->requireParams($request, 'product_category');

        return $this->permitParams($productCategory, [
            'name',
            'code',
            'description',
            'invoice_display_name',
        ]);
    }
}
