<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CatalogPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :contract factory (spec/factories/contracts.rb).
 *
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'external_id' => 'contract_'.$this->faker->uuid(),
            'name' => 'Contract '.$this->faker->words(2, true),
            'status' => 'active',
            'billing_time' => 'calendar',
            'started_at' => now()->subDays(30),
            'consolidate_invoice' => true,
            'payment_method_type' => 'provider',
        ];
    }

    /** Rails: `for_customer`. */
    public function forCustomer(Customer $customer): static
    {
        return $this->for($customer, 'customer');
    }

    /** Rails: `for_catalog_plan`. */
    public function forCatalogPlan(CatalogPlan $catalogPlan): static
    {
        return $this->for($catalogPlan, 'catalogPlan');
    }

    /** Rails trait :pending — a contract that has not started yet. */
    public function pending(string $startedAt = '+1 month'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'started_at' => now()->parse($startedAt),
        ]);
    }

    /** Rails trait :terminated. */
    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terminated',
            'terminated_at' => now(),
            'ended_at' => now(),
        ]);
    }

    /** Rails trait :canceled — a pending contract that never started. */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'canceled',
            'canceled_at' => now(),
            'started_at' => $attributes['started_at'],
        ]);
    }
}
