<?php

declare(strict_types=1);

namespace App\Services\ProductFilters;

use App\Models\Product;
use App\Services\BaseResult;
use App\Models\ProductFilter;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' ProductFilters::CreateService
 * (app/services/product_filters/create_service.rb).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Product $product,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('product_filter');
        $product = $this->product;

        try {
            if ($product === null) {
                return $result->notFoundFailure('product');
            }

            if (! $product->metered()) {
                return $result->singleValidationFailure('not_allowed_for_product_type', 'product');
            }

            $resolvedValues = ResolveValuesService::call(
                product: $product,
                valuesParams: $this->params['values'] ?? null,
            );

            if ($resolvedValues->failure()) {
                return $resolvedValues;
            }

            return DB::transaction(function () use ($result, $product, $resolvedValues): BaseResult {
                // Rails: product.with_lock — serialize filter authoring on the item.
                $product->refresh();
                Product::query()->whereKey($product->id)->lockForUpdate()->first();

                $valuesValidation = ValidateValuesService::call(
                    product: $product,
                    valuesParams: $resolvedValues->values_params,
                );

                if ($valuesValidation->failure()) {
                    return $valuesValidation;
                }

                $productFilter = new ProductFilter([
                    'organization_id' => $product->organization_id,
                    'product_id' => $product->id,
                    'name' => $this->params['name'] ?? null,
                    'code' => isset($this->params['code']) ? mb_trim((string) $this->params['code']) : null,
                    'description' => $this->params['description'] ?? null,
                    'invoice_display_name' => $this->params['invoice_display_name'] ?? null,
                ]);

                $errors = $productFilter->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $productFilter->save();

                // TODO(port): activity_loggable (product_filter.created).
                foreach ($resolvedValues->values_params as $valueParams) {
                    $value = new \App\Models\ProductFilterValue([
                        'organization_id' => $product->organization_id,
                        'product_filter_id' => $productFilter->id,
                        'billable_metric_filter_id' => $valueParams['billable_metric_filter_id'],
                        'value' => $valueParams['value'] ?? null,
                    ]);

                    $valueErrors = $value->validateAttributes();
                    if ($valueErrors !== []) {
                        $messages = $valueErrors;
                        $prefixed = [];
                        foreach ($messages as $field => $codes) {
                            $prefixed['values.'.$field] = $codes;
                        }

                        $result->recordValidationFailure($prefixed)->raiseIfError();
                    }

                    $value->save();
                }

                $result->product_filter = $productFilter;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
