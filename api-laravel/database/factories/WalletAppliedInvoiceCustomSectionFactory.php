<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Wallet;
use App\Models\WalletAppliedInvoiceCustomSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :Wallet applied_invoice_custom_section factory
 * (Wallet::AppliedInvoiceCustomSection — the Wallet join table).
 *
 * @extends Factory<WalletAppliedInvoiceCustomSection>
 */
class WalletAppliedInvoiceCustomSectionFactory extends Factory
{
    protected $model = WalletAppliedInvoiceCustomSection::class;

    public function definition(): array
    {
        return [
            'wallet_id' => WalletFactory::new(),
            'invoice_custom_section_id' => InvoiceCustomSectionFactory::new(),
            'organization_id' => function (array $attributes): string {
                $parent = $attributes['wallet_id'] instanceof Wallet
                    ? $attributes['wallet_id']
                    : Wallet::query()->findOrFail((string) $attributes['wallet_id']);

                return (string) $parent->organization_id;
            },
        ];
    }
}
