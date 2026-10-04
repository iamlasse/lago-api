<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\Customer;
use App\Models\OrderForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :order factory (spec/factories/orders.rb) — the default
 * order_form is the :signed trait's, and the traits mirror Rails'
 * :executed_in_lago / :executed_order_only / :failed.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'order_form_id' => OrderFormFactory::new()->signed(),
            'status' => 'created',
        ];
    }

    /** Rails trait :executed_in_lago. */
    public function executedInLago(): static
    {
        return $this->state(fn (): array => [
            'status' => 'executed',
            'execution_mode' => 'execute_in_lago',
            'executed_at' => now(),
        ]);
    }

    /** Rails trait :executed_order_only. */
    public function executedOrderOnly(): static
    {
        return $this->state(fn (): array => [
            'status' => 'executed',
            'execution_mode' => 'order_only',
            'executed_at' => now(),
        ]);
    }

    /** Rails trait :failed. */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'execution_mode' => 'order_only',
            'execution_record' => ['errors' => ['something went wrong']],
        ]);
    }

    public function executionMode(?string $executionMode): static
    {
        return $this->state(fn (): array => ['execution_mode' => $executionMode]);
    }

    /** Pin the customer (and its organization). */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    public function forOrderForm(OrderForm $orderForm): static
    {
        return $this->state(fn (): array => [
            'order_form_id' => $orderForm->id,
            'organization_id' => $orderForm->organization_id,
            'customer_id' => $orderForm->customer_id,
        ]);
    }
}
