<?php

declare(strict_types=1);

namespace App\Services\ProductCategories;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' ProductCategories::DestroyService
 * (app/services/product_categories/destroy_service.rb) — discards the
 * category's products first (through Products::DestroyService), then the
 * category itself.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?ProductCategory $productCategory,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('product_category');
        $productCategory = $this->productCategory;

        try {
            if ($productCategory === null) {
                return $result->notFoundFailure('product_category');
            }

            if ($productCategory->attachedToPlanOrSubscription()) {
                return $result->singleValidationFailure('attached_to_plan_or_subscription', 'product_category');
            }

            DB::transaction(function () use ($productCategory): void {
                $productCategory->products->each(function ($product): void {
                    \App\Services\Products\DestroyService::call(product: $product)->raiseIfError();
                });

                // TODO(port): activity_loggable (product_category.deleted).
                $productCategory->delete();
            });

            $result->product_category = $productCategory;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
