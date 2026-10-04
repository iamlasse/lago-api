<?php

declare(strict_types=1);

namespace App\Services\ContractRateCards;

use App\Models\Contract;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' ContractRateCards::CreateService
 * (app/services/contract_rate_cards/create_service.rb) — attaches a rate
 * card to a contract, pricing it through its own rate phases. Authoring is
 * pending-only: once the contract is active its cards are signed.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Contract $contract,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('contract_rate_card');
        $contract = $this->contract;
        $params = $this->params;

        try {
            if ($contract === null) {
                return $result->notFoundFailure('contract');
            }

            if (! $contract->editable()) {
                return $result->singleValidationFailure('contract_locked', 'contract');
            }

            // Date column: a malformed value would silently cast to null
            // instead of failing, so the format is rejected explicitly.
            if (($params['billing_anchor_date'] ?? null) !== null
                && ! Datetime::validFormat($params['billing_anchor_date'], 'any')) {
                return $result->singleValidationFailure('value_is_invalid', 'billing_anchor_date');
            }

            $rateCard = $contract->organization->rateCards()
                ->where('code', $params['rate_card_code'] ?? null)
                ->first();

            if ($rateCard === null) {
                return $result->notFoundFailure('rate_card');
            }

            // Fees bill in the card currency and the invoice in the
            // contract's currency (its plan's, or the customer's for a
            // plan-less contract); a mismatch must fail at configuration
            // time.
            if ($rateCard->currency !== $contract->currency()) {
                return $result->singleValidationFailure('currency_does_not_match', 'currency');
            }

            return DB::transaction(function () use ($result, $contract, $rateCard, $params): BaseResult {
                // Rails: contract.with_lock.
                Contract::query()->whereKey($contract->id)->lockForUpdate()->first();

                // One card per pricing slice: a contract may hold several
                // cards of the same item only when they cover different
                // filter slices (default + EU).
                $slicePriced = $contract->appliedRateCards()
                    ->join('rate_cards', 'rate_cards.id', '=', 'contract_rate_cards.rate_card_id')
                    ->where('rate_cards.product_id', $rateCard->product_id)
                    ->where('rate_cards.product_filter_id', $rateCard->product_filter_id)
                    ->whereNull('contract_rate_cards.deleted_at')
                    ->exists();

                if ($slicePriced) {
                    $errorCode = $rateCard->product_filter_id !== null
                        ? 'product_filter_already_priced'
                        : 'product_already_priced';

                    return $result->singleValidationFailure($errorCode, 'rate_card');
                }

                $lifecycle = $contract->defaultRateCardLifecycle($params['billing_anchor_date'] ?? null);

                $contractRateCard = $contract->appliedRateCards()->create([
                    'organization_id' => $contract->organization_id,
                    'rate_card_id' => $rateCard->id,
                    'units' => $params['units'] ?? null,
                    'billing_anchor_date' => $lifecycle['billing_anchor_date'],
                    'effective_date' => $lifecycle['effective_date'],
                    'next_billing_at' => $lifecycle['next_billing_at'],
                ]);

                $errors = $contractRateCard->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                // Phases can be authored atomically with the card: a
                // provided sequence goes through the same validations as the
                // single-phase ops (an explicit empty list is rejected
                // there) and a failure rolls the whole create back. Omitted
                // or null, the card starts on a single default terminal
                // phase.
                if (array_key_exists('rate_phases', $params) && $params['rate_phases'] !== null) {
                    \App\Services\RatePhases\ReplaceService::callBang(
                        contractRateCard: $contractRateCard,
                        phasesParams: $params['rate_phases'],
                    );
                } else {
                    \App\Services\RatePhases\CreateService::callBang(
                        contractRateCard: $contractRateCard,
                        params: ['code' => 'default', 'position' => 1],
                    );
                }

                $result->contract_rate_card = $contractRateCard;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
