<?php

declare(strict_types=1);

namespace App\Services\ProductFilters;

use App\Services\BaseResult;
use App\Models\ProductFilter;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' ProductFilters::UpdateService
 * (app/services/product_filters/update_service.rb).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?ProductFilter $productFilter,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('product_filter');
        $productFilter = $this->productFilter;

        try {
            if ($productFilter === null) {
                return $result->notFoundFailure('product_filter');
            }

            $resolvedValues = null;
            if (array_key_exists('values', $this->params)) {
                $resolvedValues = ResolveValuesService::call(
                    product: $productFilter->product,
                    valuesParams: $this->params['values'],
                );

                if ($resolvedValues->failure()) {
                    return $resolvedValues;
                }
            }

            return DB::transaction(function () use ($result, $productFilter, $resolvedValues): BaseResult {
                // Rails: product_filter.product.with_lock.
                \App\Models\Product::query()->whereKey($productFilter->product_id)->lockForUpdate()->first();

                // NOTE: the code freezes as soon as the item is in a plan or
                // subscription.
                if ($productFilter->attachedToPlanOrSubscription()
                    && array_key_exists('code', $this->params)
                    && mb_trim((string) ($this->params['code'] ?? '')) !== $productFilter->code) {
                    return $result->singleValidationFailure('attached_to_plan_or_subscription', 'code');
                }

                $valuesChanged = false;
                if ($resolvedValues !== null) {
                    $valuesChanged = $this->valuesChanged($productFilter, $resolvedValues);

                    if ($productFilter->attachedToSubscriptions() && $valuesChanged) {
                        return $result->singleValidationFailure('attached_to_subscriptions', 'values');
                    }

                    $valuesValidation = ValidateValuesService::call(
                        product: $productFilter->product,
                        valuesParams: $resolvedValues->values_params,
                        productFilter: $productFilter,
                    );

                    if ($valuesValidation->failure()) {
                        return $valuesValidation;
                    }
                }

                if (array_key_exists('name', $this->params)) {
                    $productFilter->name = $this->params['name'];
                }
                if (array_key_exists('description', $this->params)) {
                    $productFilter->description = $this->params['description'];
                }
                if (array_key_exists('invoice_display_name', $this->params)) {
                    $productFilter->invoice_display_name = $this->params['invoice_display_name'];
                }
                if (array_key_exists('code', $this->params)) {
                    $productFilter->code = $this->params['code'] === null
                        ? null
                        : mb_trim((string) $this->params['code']);
                }

                $errors = $productFilter->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                // TODO(port): activity_loggable (product_filter.updated).
                $productFilter->save();

                if ($resolvedValues !== null && $valuesChanged) {
                    $this->replaceValues($productFilter, $resolvedValues);
                }

                $result->product_filter = $productFilter;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function currentPairs(ProductFilter $productFilter): array
    {
        return $productFilter->values->map(fn ($value): array => [
            (string) $value->billable_metric_filter_id,
            (string) $value->value,
        ])->sort()->values()->all();
    }

    private function valuesChanged(ProductFilter $productFilter, ResolveValuesService $resolvedValues): bool
    {
        $submitted = array_map(
            fn (array $entry): array => [(string) $entry['billable_metric_filter_id'], (string) ($entry['value'] ?? '')],
            $resolvedValues->values_params,
        );
        usort($submitted, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $this->currentPairs($productFilter) !== $submitted;
    }

    private function replaceValues(ProductFilter $productFilter, ResolveValuesService $resolvedValues): void
    {
        $now = now();

        $productFilter->values()->whereNull('deleted_at')->update(['deleted_at' => $now]);

        foreach ($resolvedValues->values_params as $valueParams) {
            $productFilter->values()->create([
                'organization_id' => $productFilter->organization_id,
                'billable_metric_filter_id' => $valueParams['billable_metric_filter_id'],
                'value' => $valueParams['value'] ?? null,
            ]);
        }
    }
}
