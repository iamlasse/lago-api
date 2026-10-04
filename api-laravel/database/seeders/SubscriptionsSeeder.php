<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' db/seeds/20_subscriptions.rb: five noise customers
 * (cust_1..cust_5) with one active calendar subscription each
 * (sub_1..sub_5), started 6 months ago.
 */
class SubscriptionsSeeder extends Seeder
{
    public function run(): void
    {
        // The Subscription model's sequence helper requires a transaction.
        DB::transaction(function (): void {
            $this->seed();
        });
    }

    private function seed(): void
    {
        $organization = Organization::query()->where('name', 'Hooli')->firstOrFail();
        $plan = Plan::query()->where('organization_id', $organization->id)
            ->where('code', 'standard_plan')->firstOrFail();
        $billingEntity = $organization->defaultBillingEntity;
        $startedAt = now()->subMonths(6);

        for ($i = 1; $i <= 5; $i++) {
            $customer = Customer::firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'billing_entity_id' => $billingEntity->id,
                    'external_id' => "cust_{$i}",
                ],
                [
                    'name' => fake()->name(),
                    'country' => fake()->countryCode(),
                    'address_line1' => fake()->streetAddress(),
                    'address_line2' => fake()->secondaryAddress(),
                    'zipcode' => fake()->postcode(),
                    'email' => fake()->safeEmail(),
                    'city' => fake()->city(),
                    'legal_name' => fake()->company(),
                    'legal_number' => fake()->uuid(),
                    'currency' => 'EUR',
                ],
            );

            Subscription::firstOrCreate(
                [
                    'customer_id' => $customer->id,
                    'external_id' => "sub_{$i}",
                    'plan_id' => $plan->id,
                ],
                [
                    'organization_id' => $organization->id,
                    'status' => 'active',
                    'billing_time' => 'calendar',
                    'started_at' => $startedAt,
                    'subscription_at' => $startedAt,
                    'created_at' => $startedAt,
                ],
            );
        }
    }
}
