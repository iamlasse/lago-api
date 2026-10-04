<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Products::CreateService
 * (app/services/products/create_service.rb).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('product');

        try {
            if ($this->organization === null) {
                return $result->notFoundFailure('organization');
            }

            $productCategory = null;
            if (($this->params['product_category_id'] ?? null) !== null) {
                $productCategory = $this->organization->productCategories()
                    ->whereKey($this->params['product_category_id'])
                    ->first();

                if ($productCategory === null) {
                    return $result->notFoundFailure('product_category');
                }
            }

            $billableMetric = null;
            if (($this->params['billable_metric_id'] ?? null) !== null) {
                $billableMetric = $this->organization->billableMetrics()
                    ->whereKey($this->params['billable_metric_id'])
                    ->first();

                if ($billableMetric === null) {
                    return $result->notFoundFailure('billable_metric');
                }
            }

            // TODO(port): activity_loggable (product.created audit log).
            $product = DB::transaction(function () use ($result, $productCategory, $billableMetric): Product {
                $product = new Product([
                    'organization_id' => $this->organization->id,
                    'product_category_id' => $productCategory?->id,
                    'billable_metric_id' => $billableMetric?->id,
                    'product_type' => $this->params['product_type'] ?? null,
                    'name' => $this->params['name'] ?? null,
                    'code' => isset($this->params['code']) ? mb_trim((string) $this->params['code']) : null,
                    'description' => $this->params['description'] ?? null,
                    'invoice_display_name' => $this->params['invoice_display_name'] ?? null,
                ]);

                $errors = $product->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $product->save();

                return $product;
            });

            $result->product = $product;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
