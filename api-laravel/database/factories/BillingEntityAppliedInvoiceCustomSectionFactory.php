<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillingEntity;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\BillingEntityAppliedInvoiceCustomSection;

/**
 * Port of Rails' :billing_entity_applied_invoice_custom_section factory (BillingEntity::AppliedInvoiceCustomSection —
 * the BillingEntity join table).
 *
 * @extends Factory<BillingEntityAppliedInvoiceCustomSection>
 */
class BillingEntityAppliedInvoiceCustomSectionFactory extends Factory
{
    protected $model = BillingEntityAppliedInvoiceCustomSection::class;

    public function definition(): array
    {
        return [
            'billing_entity_id' => BillingEntityFactory::new(),
            'invoice_custom_section_id' => InvoiceCustomSectionFactory::new(),
            'organization_id' => function (array $attributes): string {
                $parent = $attributes['billing_entity_id'] instanceof BillingEntity
                    ? $attributes['billing_entity_id']
                    : BillingEntity::query()->findOrFail((string) $attributes['billing_entity_id']);

                return (string) $parent->organization_id;
            },
        ];
    }
}
