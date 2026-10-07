<?php

declare(strict_types=1);

namespace App\Services\RateCards;

use App\Models\Product;
use App\Models\RateCard;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' RateCards::CreateService
 * (app/services/rate_cards/create_service.rb).
 */
class CreateService extends BaseService
{
    /** Rails: ValidatesBooleanParams::BOOLEAN_FIELDS. */
    private const array BOOLEAN_FIELDS = ['proration', 'display_on_invoice'];

    public function __construct(
        private readonly ?Product $product,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_card');
        $product = $this->product;
        $params = $this->params;

        try {
            if ($product === null) {
                return $result->notFoundFailure('product');
            }

            $booleanFailure = $this->booleanParamsFailure($result);
            if ($booleanFailure !== null) {
                return $booleanFailure;
            }

            $productFilter = null;
            if (($params['product_filter_id'] ?? null) !== null) {
                $productFilter = $product->filters()->whereKey($params['product_filter_id'])->first();

                if ($productFilter === null) {
                    return $result->notFoundFailure('product_filter');
                }
            }

            if (($params['applied_pricing_unit_code'] ?? null) !== null
                && ! DB::table('pricing_units')
                    ->where('organization_id', $product->organization_id)
                    ->where('code', $params['applied_pricing_unit_code'])
                    ->exists()) {
                return $result->singleValidationFailure('value_is_invalid', 'applied_pricing_unit_code');
            }

            $attributes = [
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'product_filter_id' => $productFilter?->id,
                'name' => $params['name'] ?? null,
                'code' => isset($params['code']) ? mb_trim((string) $params['code']) : null,
                'description' => $params['description'] ?? null,
                'currency' => $params['currency'] ?? null,
                // NOT NULL columns with DB defaults: only set when a value is given.
                'billing_timing' => $params['billing_timing'] ?? 'arrears',
                'applied_pricing_unit_code' => $params['applied_pricing_unit_code'] ?? null,
            ];

            if (($params['proration'] ?? null) !== null) {
                $attributes['proration'] = $params['proration'];
            }
            if (($params['display_on_invoice'] ?? null) !== null) {
                $attributes['display_on_invoice'] = $params['display_on_invoice'];
            }
            // Nullable: nil (omitted or explicit) stores NULL — the fee stays
            // standalone.
            $attributes['regroup_paid_fees'] = $params['regroup_paid_fees'] ?? null;

            // TODO(port): activity_loggable (rate_card.created).
            $rateCard = DB::transaction(function () use ($result, $attributes): RateCard {
                $rateCard = new RateCard($attributes);

                $errors = $rateCard->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $rateCard->save();

                $this->applyTaxes($result, $rateCard);
                $this->createRates($result, $rateCard);

                return $rateCard;
            });

            $result->rate_card = $rateCard;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /** Rails: `apply_taxes` — only when tax_codes is a present key. */
    private function applyTaxes(BaseResult $result, RateCard $rateCard): void
    {
        if (! array_key_exists('tax_codes', $this->params) || $this->params['tax_codes'] === null) {
            return;
        }

        ApplyTaxesService::callBang(rateCard: $rateCard, taxCodes: $this->params['tax_codes']);
    }

    /**
     * Rails: `create_rates` — a nested rate failure re-fails with the
     * `rates.` field prefix.
     */
    private function createRates(BaseResult $result, RateCard $rateCard): void
    {
        foreach ((array) ($this->params['rates'] ?? []) as $rateParams) {
            try {
                \App\Services\RateCardRates\CreateService::callBang(
                    rateCard: $rateCard,
                    params: $rateParams,
                );
            } catch (FailedResult $e) {
                $messages = (array) ($e->messages ?? []);

                $prefixed = [];
                foreach ($messages as $field => $codes) {
                    $prefixed['rates.'.$field] = $codes;
                }

                $result->validationFailure($prefixed)->raiseIfError();
            }
        }
    }

    /**
     * Rails: ValidatesBooleanParams — reject non-boolean input on the
     * permissive REST layer (nil is allowed).
     */
    private function booleanParamsFailure(BaseResult $result): ?BaseResult
    {
        $invalid = [];

        foreach (self::BOOLEAN_FIELDS as $field) {
            $value = $this->params[$field] ?? null;

            if (array_key_exists($field, $this->params) && $value !== null && ! is_bool($value)) {
                $invalid[$field] = ['value_is_invalid'];
            }
        }

        if ($invalid === []) {
            return null;
        }

        return $result->validationFailure($invalid);
    }
}
