<?php

declare(strict_types=1);

namespace App\Services\ProductFilters;

use App\Services\BaseResult;
use App\Models\ProductFilter;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' ProductFilters::DestroyService
 * (app/services/product_filters/destroy_service.rb) — a filter cannot be
 * deleted while its item is attached to a plan or subscription; the cards
 * scoped to it lose their slice, so they are discarded too.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?ProductFilter $productFilter,
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

            if ($productFilter->attachedToPlanOrSubscription()) {
                return $result->singleValidationFailure('attached_to_plan_or_subscription', 'product_filter');
            }

            DB::transaction(function () use ($productFilter): void {
                // Rate cards scoped to this filter are discarded with it.
                $scopedCards = $productFilter->product->rateCards()
                    ->where('product_filter_id', $productFilter->id);

                DB::table('rate_card_rates')
                    ->whereIn('rate_card_id', $scopedCards->select('rate_cards.id'))
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);

                $scopedCards->whereNull('deleted_at')->update(['deleted_at' => now()]);

                $productFilter->values()->whereNull('deleted_at')->update(['deleted_at' => now()]);

                // TODO(port): activity_loggable (product_filter.deleted).
                $productFilter->delete();
            });

            $result->product_filter = $productFilter;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
