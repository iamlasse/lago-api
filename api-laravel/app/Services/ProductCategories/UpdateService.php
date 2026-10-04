<?php

declare(strict_types=1);

namespace App\Services\ProductCategories;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ProductCategory;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' ProductCategories::UpdateService
 * (app/services/product_categories/update_service.rb).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?ProductCategory $productCategory,
        private readonly array $params,
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

            if (array_key_exists('name', $this->params)) {
                $productCategory->name = $this->params['name'];
            }
            if (array_key_exists('description', $this->params)) {
                $productCategory->description = $this->params['description'];
            }
            if (array_key_exists('invoice_display_name', $this->params)) {
                $productCategory->invoice_display_name = $this->params['invoice_display_name'];
            }

            // NOTE: the code can only be edited while the category is not yet
            // attached to a plan or subscription.
            if (array_key_exists('code', $this->params)
                && mb_trim((string) ($this->params['code'] ?? '')) !== $productCategory->code
                && $productCategory->attachedToPlanOrSubscription()) {
                return $result->singleValidationFailure('attached_to_plan_or_subscription', 'code');
            }

            if (array_key_exists('code', $this->params)) {
                $productCategory->code = $this->params['code'] === null
                    ? null
                    : mb_trim((string) $this->params['code']);
            }

            // TODO(port): activity_loggable (product_category.updated).
            $errors = $productCategory->validateAttributes();
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $productCategory->save();

            $result->product_category = $productCategory;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
