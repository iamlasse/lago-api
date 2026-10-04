<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Invoice;
use App\Models\Customer;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\FeePaymentStatus;
use App\Models\WalletTransaction;

/**
 * Port of Rails' Fees::PaidCreditService
 * (app/services/fees/paid_credit_service.rb) — the credit fee a purchased
 * wallet transaction bills onto its invoice.
 */
class PaidCreditService extends BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly WalletTransaction $walletTransaction,
        private readonly ?Customer $customer = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fee');

        $existingFee = $this->alreadyBilledFee();

        if ($existingFee !== null) {
            $result->fee = $existingFee;

            return $result;
        }

        $amountCents = $this->walletTransaction->amountCents();
        $unitAmountCents = $this->walletTransaction->unitAmountCents();

        $fee = new Fee([
            'invoice_id' => $this->invoice->id,
            'organization_id' => $this->invoice->organization_id,
            'billing_entity_id' => $this->invoice->billing_entity_id,
            'fee_type' => FeeType::Credit,
            'invoiceable_type' => 'WalletTransaction',
            'invoiceable_id' => $this->walletTransaction->id,
            'amount_cents' => $amountCents,
            'precise_amount_cents' => (string) $amountCents,
            'amount_currency' => $this->walletTransaction->wallet->currency,
            'unit_amount_cents' => $unitAmountCents,
            'units' => (string) $this->walletTransaction->credit_amount,
            'payment_status' => FeePaymentStatus::Pending,

            // NOTE: No taxes should be applied as it can be considered as an advance.
            'taxes_rate' => 0,
            'taxes_amount_cents' => 0,
            'taxes_precise_amount_cents' => '0',
        ]);

        // Rails: precise_unit_amount = unit_amount.to_f — the
        // units-denominated amount (unit_amount_cents / subunit_to_unit).
        $fee->precise_unit_amount = MoneyMath::fdiv(
            (string) $unitAmountCents,
            (string) \App\Support\Currency::subunitToUnit((string) $this->walletTransaction->wallet->currency),
        );

        $fee->save();

        $result->fee = $fee;

        return $result;
    }

    private function alreadyBilledFee(): ?Fee
    {
        return $this->invoice->fees()
            ->where('invoiceable_id', $this->walletTransaction->id)
            ->where('invoiceable_type', 'WalletTransaction')
            ->first();
    }
}
