<?php

declare(strict_types=1);

namespace App\Services\RateCards;

use App\Models\RateCard;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' RateCards::ApplyTaxesService
 * (app/services/rate_cards/apply_taxes_service.rb).
 */
class ApplyTaxesService extends BaseService
{
    public function __construct(
        private readonly ?RateCard $rateCard,
        private readonly array $taxCodes,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_taxes');
        $rateCard = $this->rateCard;

        try {
            if ($rateCard === null) {
                return $result->notFoundFailure('rate_card');
            }

            $taxCodes = array_values(array_unique($this->taxCodes));

            $taxesByCode = $rateCard->organization->taxes()
                ->whereIn('code', $taxCodes)
                ->get()
                ->keyBy('code');

            if (array_diff($taxCodes, $taxesByCode->keys()->all()) !== []) {
                return $result->notFoundFailure('tax');
            }

            // Rails: rate_card.with_lock.
            return DB::transaction(function () use ($result, $rateCard, $taxCodes, $taxesByCode): BaseResult {
                RateCard::query()->whereKey($rateCard->id)->lockForUpdate()->first();

                $keptTaxIds = $taxesByCode->pluck('id')->all();

                $rateCard->appliedTaxes()
                    ->whereNotIn('tax_id', $keptTaxIds)
                    ->delete();

                $appliedTaxes = [];
                foreach ($taxCodes as $taxCode) {
                    $appliedTaxes[] = $rateCard->appliedTaxes()->firstOrCreate([
                        'tax_id' => $taxesByCode->get($taxCode)->id,
                    ], ['organization_id' => $rateCard->organization_id]);
                }

                $result->applied_taxes = $appliedTaxes;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
