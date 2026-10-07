<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\WalletTransactionAppliedInvoiceCustomSection;

/**
 * Port of Rails' :WalletTransaction applied_invoice_custom_section factory
 * (WalletTransaction::AppliedInvoiceCustomSection — the WalletTransaction join table).
 *
 * @extends Factory<WalletTransactionAppliedInvoiceCustomSection>
 */
class WalletTransactionAppliedInvoiceCustomSectionFactory extends Factory
{
    protected $model = WalletTransactionAppliedInvoiceCustomSection::class;

    public function definition(): array
    {
        return [
            'wallet_transaction_id' => WalletTransactionFactory::new(),
            'invoice_custom_section_id' => InvoiceCustomSectionFactory::new(),
            'organization_id' => function (array $attributes): string {
                $parent = $attributes['wallet_transaction_id'] instanceof WalletTransaction
                    ? $attributes['wallet_transaction_id']
                    : WalletTransaction::query()->findOrFail((string) $attributes['wallet_transaction_id']);

                return (string) $parent->organization_id;
            },
        ];
    }
}
