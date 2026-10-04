<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' Products::UpdateService
 * (app/services/products/update_service.rb).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?Product $product,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('product');
        $product = $this->product;
        $params = $this->params;

        try {
            if ($product === null) {
                return $result->notFoundFailure('product');
            }

            if (array_key_exists('name', $params)) {
                $product->name = $params['name'];
            }
            if (array_key_exists('description', $params)) {
                $product->description = $params['description'];
            }
            if (array_key_exists('invoice_display_name', $params)) {
                $product->invoice_display_name = $params['invoice_display_name'];
            }

            if ($product->attachedToPlanOrSubscription()) {
                if (array_key_exists('code', $params) && isset($params['code']) && mb_trim((string) $params['code']) !== $product->code) {
                    return $result->singleValidationFailure('attached_to_plan_or_subscription', 'code');
                }

                if (array_key_exists('product_category_id', $params)
                    && $params['product_category_id'] !== $product->product_category_id) {
                    return $result->singleValidationFailure('attached_to_plan_or_subscription', 'product_category');
                }
            } else {
                if (array_key_exists('code', $params)) {
                    $product->code = $params['code'] === null ? null : mb_trim((string) $params['code']);
                }

                if (array_key_exists('product_category_id', $params)) {
                    $failure = $this->assignProductCategory($result);
                    if ($failure !== null) {
                        return $failure;
                    }
                }
            }

            // TODO(port): activity_loggable (product.updated audit log).
            return DB::transaction(function () use ($result, $product): BaseResult {
                $errors = $product->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $product->save();

                $result->product = $product;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /** Rails: `assign_product_category` — null detaches the product. */
    private function assignProductCategory(BaseResult $result): ?BaseResult
    {
        $product = $this->product;
        $categoryId = $this->params['product_category_id'] ?? null;

        if ($categoryId === null || $categoryId === '') {
            $product->productCategory()->dissociate();

            return null;
        }

        $productCategory = $product->organization->productCategories()
            ->whereKey($categoryId)
            ->first();

        if ($productCategory === null) {
            return $result->notFoundFailure('product_category');
        }

        $product->productCategory()->associate($productCategory);

        return null;
    }
}
