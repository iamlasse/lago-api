<?php

declare(strict_types=1);

namespace App\Services\RateCards;

use App\Models\RateCard;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' RateCards::UpdateService
 * (app/services/rate_cards/update_service.rb).
 */
class UpdateService extends BaseService
{
    /**
     * Billing-semantic fields freeze once a rate exists — changing them
     * would alter what the existing rates mean; create a new card instead.
     */
    private const LOCKED_WITH_RATES = [
        'currency',
        'applied_pricing_unit_code',
        'billing_timing',
        'proration',
        'regroup_paid_fees',
        'display_on_invoice',
    ];

    private const BOOLEAN_FIELDS = ['proration', 'display_on_invoice'];

    public function __construct(
        private readonly ?RateCard $rateCard,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_card');
        $rateCard = $this->rateCard;
        $params = $this->params;

        try {
            if ($rateCard === null) {
                return $result->notFoundFailure('rate_card');
            }

            $booleanFailure = $this->booleanParamsFailure($result);
            if ($booleanFailure !== null) {
                return $booleanFailure;
            }

            if (($params['applied_pricing_unit_code'] ?? null) !== null
                && ! DB::table('pricing_units')
                    ->where('organization_id', $rateCard->organization_id)
                    ->where('code', $params['applied_pricing_unit_code'])
                    ->exists()) {
                return $result->singleValidationFailure('value_is_invalid', 'applied_pricing_unit_code');
            }

            if ($rateCard->rates()->exists()) {
                foreach (self::LOCKED_WITH_RATES as $lockedField) {
                    if (array_key_exists($lockedField, $params) && $params[$lockedField] !== $rateCard->{$lockedField}) {
                        return $result->singleValidationFailure('not_editable_with_rates', $lockedField);
                    }
                }
            }

            // An attachment is created only when the card and its plan share
            // a currency, so the currency freezes once the card is attached.
            if (array_key_exists('currency', $params)
                && $params['currency'] !== $rateCard->currency
                && $rateCard->attachedToPlanOrSubscription()) {
                return $result->singleValidationFailure('attached_to_plan_or_subscription', 'currency');
            }

            // Code is identity: editable until the card is in a plan or
            // subscription.
            if (array_key_exists('code', $params)
                && mb_trim((string) ($params['code'] ?? '')) !== $rateCard->code
                && $rateCard->attachedToPlanOrSubscription()) {
                return $result->singleValidationFailure('attached_to_plan_or_subscription', 'code');
            }

            // TODO(port): activity_loggable (rate_card.updated).
            return DB::transaction(function () use ($result, $rateCard): BaseResult {
                $params = $this->params;

                if (array_key_exists('code', $params)) {
                    $rateCard->code = $params['code'] === null ? null : mb_trim((string) $params['code']);
                }
                if (array_key_exists('name', $params)) {
                    $rateCard->name = $params['name'];
                }
                if (array_key_exists('description', $params)) {
                    $rateCard->description = $params['description'];
                }
                if (array_key_exists('currency', $params)) {
                    $rateCard->currency = $params['currency'];
                }
                if (array_key_exists('billing_timing', $params)) {
                    $rateCard->billing_timing = $params['billing_timing'];
                }
                if (array_key_exists('proration', $params)) {
                    $rateCard->proration = $params['proration'];
                }
                if (array_key_exists('display_on_invoice', $params)) {
                    $rateCard->display_on_invoice = $params['display_on_invoice'];
                }
                if (array_key_exists('regroup_paid_fees', $params)) {
                    $rateCard->regroup_paid_fees = $params['regroup_paid_fees'];
                }
                if (array_key_exists('applied_pricing_unit_code', $params)) {
                    $rateCard->applied_pricing_unit_code = $params['applied_pricing_unit_code'];
                }

                $errors = $rateCard->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $rateCard->save();
                $this->applyTaxes($rateCard);

                $result->rate_card = $rateCard;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /** Rails: `apply_taxes` — only when tax_codes is a present key. */
    private function applyTaxes(RateCard $rateCard): void
    {
        if (! array_key_exists('tax_codes', $this->params) || $this->params['tax_codes'] === null) {
            return;
        }

        ApplyTaxesService::callBang(rateCard: $rateCard, taxCodes: $this->params['tax_codes']);
    }

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
