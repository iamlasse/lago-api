<?php

declare(strict_types=1);

namespace App\Services\ContractRateCards;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use App\Models\ContractRateCard;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' ContractRateCards::UpdateService
 * (app/services/contract_rate_cards/update_service.rb) — edits a contract's
 * rate card entry. While the contract is pending the entry is freely
 * editable (authoring window). Once the contract is active its cards are
 * signed; unit versioning on an active contract is a lifecycle concern
 * priced by the billing engine, not an authoring edit here.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?ContractRateCard $contractRateCard,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('contract_rate_card');
        $contractRateCard = $this->contractRateCard;

        try {
            if ($contractRateCard === null) {
                return $result->notFoundFailure('applied_rate_card');
            }

            if (! $contractRateCard->contract->editable()) {
                return $result->singleValidationFailure('contract_locked', 'contract');
            }

            if (array_key_exists('billing_anchor_date', $this->params)
                && ! Datetime::validFormat((string) ($this->params['billing_anchor_date'] ?? ''), 'any')) {
                return $result->singleValidationFailure('value_is_invalid', 'billing_anchor_date');
            }

            if (array_key_exists('units', $this->params)) {
                $contractRateCard->units = $this->params['units'];
            }
            if (array_key_exists('billing_anchor_date', $this->params)) {
                $contractRateCard->billing_anchor_date = $this->params['billing_anchor_date'];
            }

            $errors = $contractRateCard->validateAttributes();
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $contractRateCard->save();

            $result->contract_rate_card = $contractRateCard;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
