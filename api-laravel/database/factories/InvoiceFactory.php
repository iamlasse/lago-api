<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceTaxStatus;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :invoice factory (spec/factories/invoices.rb).
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'billing_entity_id' => function (array $attributes): string {
                $customer = Customer::query()->find($attributes['customer_id']);

                return $customer->billing_entity_id
                    ?? BillingEntityFactory::new()->create(['organization_id' => $attributes['organization_id']])->id;
            },
            'invoice_type' => InvoiceType::Subscription,
            'status' => InvoiceStatus::Finalized,
            'payment_status' => InvoicePaymentStatus::Pending,
            'tax_status' => InvoiceTaxStatus::Succeeded->value,
            'currency' => 'EUR',
            'issuing_date' => now('UTC')->toDateString(),
            'payment_due_date' => now('UTC')->addDays(30)->toDateString(),
            'net_payment_term' => 30,
            'timezone' => 'UTC',
            'fees_amount_cents' => 0,
            'coupons_amount_cents' => 0,
            'credit_notes_amount_cents' => 0,
            'sub_total_excluding_taxes_amount_cents' => 0,
            'sub_total_including_taxes_amount_cents' => 0,
            'total_amount_cents' => 0,
            'taxes_amount_cents' => 0,
            'progressive_billing_credit_amount_cents' => 0,
            'prepaid_credit_amount_cents' => 0,
            'total_paid_amount_cents' => 0,
            'skip_charges' => false,
            'self_billed' => false,
            'version_number' => 3,
            'ready_for_payment_processing' => false,
            'ready_to_be_refreshed' => false,
        ];
    }

    public function generating(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Generating]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Draft]);
    }

    public function finalized(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Finalized]);
    }
}
