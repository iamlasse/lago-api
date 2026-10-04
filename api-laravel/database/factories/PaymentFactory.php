<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :payment factory — a succeeded provider payment on an
 * invoice payable (Rails' :payment_factory payable factory builds the
 * invoice; here the caller usually attaches one with forInvoice()).
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'customer_id' => function (array $attributes): string {
                $customer = Customer::query()->find($attributes['customer_id'] ?? null);

                return $customer->id ?? CustomerFactory::new()->create([
                    'organization_id' => $attributes['organization_id'],
                ])->id;
            },
            'amount_cents' => 100,
            'amount_currency' => 'EUR',
            'status' => 'succeeded',
            'payable_payment_status' => 'succeeded',
            'payment_type' => 'provider',
            'payable_type' => 'Invoice',
            'payable_id' => function (array $attributes): string {
                return \App\Models\Invoice::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'customer_id' => $attributes['customer_id'],
                ])->id;
            },
        ];
    }

    /** The payment is pending (created, provider charge not settled). */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'payable_payment_status' => 'pending',
        ]);
    }

    /** The payment failed at the provider. */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'payable_payment_status' => 'failed',
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
            'payable_payment_status' => 'processing',
        ]);
    }

    /** A manual (recorded) payment. */
    public function manual(): static
    {
        return $this->state(fn () => [
            'payment_type' => 'manual',
            'status' => 'succeeded',
            'payable_payment_status' => 'succeeded',
            'reference' => 'Bank transfer #1',
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization, 'organization');
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    public function forInvoice(\App\Models\Invoice $invoice): static
    {
        return $this->state(fn () => [
            'payable_type' => 'Invoice',
            'payable_id' => $invoice->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'organization_id' => $invoice->organization_id,
            'amount_currency' => $invoice->currency,
        ]);
    }
}
