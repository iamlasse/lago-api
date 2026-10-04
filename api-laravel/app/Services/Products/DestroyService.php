<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Products::DestroyService
 * (app/services/products/destroy_service.rb) — a soft destroy that
 * cascades through the product's filters, filter values, rates and cards.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?Product $product,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('product');
        $product = $this->product;

        try {
            if ($product === null) {
                return $result->notFoundFailure('product');
            }

            if ($product->attachedToPlanOrSubscription()) {
                return $result->singleValidationFailure('attached_to_plan_or_subscription', 'product');
            }

            DB::transaction(function () use ($product): void {
                $now = Carbon::now('UTC');

                // Rails: discard_all! cascades — filter values, filters,
                // rates, cards, then the product itself.
                DB::table('product_filter_values')
                    ->whereIn('product_filter_id', $product->filters()->select('product_filters.id'))
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now]);

                $product->filters()->whereNull('deleted_at')->update(['deleted_at' => $now]);

                DB::table('rate_card_rates')
                    ->whereIn('rate_card_id', $product->rateCards()->select('rate_cards.id'))
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now]);

                $product->rateCards()->whereNull('deleted_at')->update(['deleted_at' => $now]);

                $product->delete();
            });

            // TODO(port): activity_loggable (product.deleted audit log).
            $result->product = $product;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
