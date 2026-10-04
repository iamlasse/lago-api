<?php

declare(strict_types=1);

namespace App\Services\ProductCategories;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' ProductCategories::CreateService
 * (app/services/product_categories/create_service.rb).
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
        $result = static::makeResult('product_category');

        try {
            $productCategory = $this->organization->productCategories()->make([
                'name' => $this->params['name'] ?? null,
                'code' => isset($this->params['code']) ? mb_trim((string) $this->params['code']) : null,
                'description' => $this->params['description'] ?? null,
                'invoice_display_name' => $this->params['invoice_display_name'] ?? null,
            ]);

            $errors = $productCategory->validateAttributes();
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            // TODO(port): activity_loggable (product_category.created).
            $productCategory->save();

            $result->product_category = $productCategory;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
