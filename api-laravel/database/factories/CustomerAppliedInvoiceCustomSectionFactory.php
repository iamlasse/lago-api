<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAppliedInvoiceCustomSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :customer_applied_invoice_custom_section factory (Customer::AppliedInvoiceCustomSection —
 * the Customer join table).
 *
 * @extends Factory<CustomerAppliedInvoiceCustomSection>
 */
class CustomerAppliedInvoiceCustomSectionFactory extends Factory
{
    protected $model = CustomerAppliedInvoiceCustomSection::class;

    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'invoice_custom_section_id' => InvoiceCustomSectionFactory::new(),
            'organization_id' => function (array $attributes): string {
                $parent = $attributes['customer_id'] instanceof Customer
                    ? $attributes['customer_id']
                    : Customer::query()->findOrFail((string) $attributes['customer_id']);

                return (string) $parent->organization_id;
            },
            'billing_entity_id' => function (array $attributes): string {
                $parent = $attributes['customer_id'] instanceof Customer
                    ? $attributes['customer_id']
                    : Customer::query()->findOrFail((string) $attributes['customer_id']);

                return (string) $parent->billing_entity_id;
            },
        ];
    }
}
