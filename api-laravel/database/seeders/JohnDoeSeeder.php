<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\AddOn;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Seeder;
use App\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\DB;
use App\Services\Subscriptions\CreateService;
use App\Services\Invoices\CreateOneOffService;
use App\Services\Wallets\CreateService as WalletCreateService;
use App\Services\CreditNotes\CreateService as CreditNoteCreateService;

/**
 * Port of Rails' db/seeds/02_john_doe.rb: the main demo customer, its
 * subscriptions (one with a plan override), wallets, a one-off invoice and
 * a credit note.
 *
 * Known Rails-inherited non-idempotency (commented upstream as well): the
 * one-off invoice and credit note are created on every run.
 */
class JohnDoeSeeder extends Seeder
{
    public function run(): void
    {
        // Rails services run inside transactions; the ported
        // Subscriptions\CreateService requires one (Sequence helper).
        DB::transaction(function (): void {
            $this->seed();
        });
    }

    private function seed(): void
    {
        $organization = Organization::query()->where('name', 'Hooli')->firstOrFail();
        $billingEntity = $organization->defaultBillingEntity;
        $plan = Plan::query()->where('organization_id', $organization->id)
            ->where('code', 'premium_plan')->firstOrFail();
        $addon = AddOn::query()->where('organization_id', $organization->id)
            ->where('code', 'setup_fee')->firstOrFail();

        $customerExternalId = 'cust_john-doe';
        $subExternalId = 'sub_john-doe-main';
        $startedAt = now()->subMonths(6);
        $currency = 'EUR';

        $johnDoe = Customer::firstOrCreate(
            [
                'organization_id' => $organization->id,
                'billing_entity_id' => $billingEntity->id,
                'external_id' => $customerExternalId,
            ],
            [
                'name' => 'John Doe',
                'country' => 'FR',
                'address_line1' => fake()->streetAddress(),
                'address_line2' => fake()->secondaryAddress(),
                'zipcode' => fake()->postcode(),
                'email' => 'john.doe@example.com',
                'city' => fake()->city(),
                'url' => fake()->url(),
                'phone' => fake()->phoneNumber(),
                'legal_number' => fake()->uuid(),
                'currency' => $currency,
                'created_at' => $startedAt,
            ],
        );

        // == Main subscription
        $hasMainSub = Subscription::query()
            ->where('customer_id', $johnDoe->id)
            ->where('external_id', $subExternalId)
            ->where('status', SubscriptionStatus::Active->value)
            ->exists();

        if (! $hasMainSub) {
            $subscription = CreateService::callBang(
                customer: $johnDoe,
                plan: $plan,
                params: [
                    'name' => 'Main Subscription',
                    'billing_time' => 'calendar',
                    'subscription_at' => $startedAt,
                    'started_at' => $startedAt,
                    'external_id' => $subExternalId,
                ],
            )->subscription;

            // Rails passes started_at and updates created_at after creation;
            // the ported service ignores started_at, so pin both columns.
            $subscription->started_at = $startedAt;
            $subscription->created_at = $startedAt;
            $subscription->save();
        }

        // == Wallets
        if (! $johnDoe->wallets()->where('status', 0)->exists()) {
            WalletCreateService::callBang(params: [
                'organization_id' => $organization->id,
                'customer' => $johnDoe,
                'name' => 'Main wallet',
                'rate_amount' => '3',
                'paid_top_up_min_amount_cents' => 1200,
            ]);

            // TODO(port): recurring_transaction_rules creation is TODO in
            // Wallets\CreateService — insert the premium rule directly.
            $mainWallet = $johnDoe->wallets()->where('status', 0)->oldest()->first();

            if ($mainWallet !== null) {
                DB::table('recurring_transaction_rules')->insert([
                    'wallet_id' => $mainWallet->id,
                    'organization_id' => $organization->id,
                    'trigger' => 0, // interval
                    'interval' => 0, // weekly
                    'method' => 0, // fixed
                    'granted_credits' => '10',
                    'expiration_at' => now()->addYear(),
                    'transaction_metadata' => json_encode([['key' => 'origin', 'value' => 'seeder']]),
                    'transaction_name' => '10 credits for free 🎁',
                    'status' => 0, // active
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $johnDoe->wallets()->create([
                'organization_id' => $organization->id,
                'customer_id' => $johnDoe->id,
                'name' => 'Terminated wallet',
                'rate_amount' => '1',
                'status' => 1, // terminated
                'terminated_at' => now()->subWeek(),
                'currency' => $currency,
            ]);
        }

        // == One-off invoice with credit note
        $oneOff = CreateOneOffService::callBang(
            customer: $johnDoe,
            currency: $currency,
            fees: [[
                'add_on_id' => $addon->id,
                'name' => $addon->name,
                'units' => 2,
                'unit_amount_cents' => $addon->amount_cents,
                'tax_codes' => ['lago_eu_fr_standard'],
            ]],
            timestamp: $startedAt->clone()->addDays(5)->getTimestamp(),
            skipPsp: true,
        )->invoice;

        CreditNoteCreateService::callBang(
            invoice: $oneOff,
            creditAmountCents: 4800,
            description: 'Generated by seeders',
            items: [[
                'fee_id' => $oneOff->fees()->sole()->id,
                'amount_cents' => 4000,
            ]],
        );

        // == Second subscription with a plan override (TODO(port):
        // plan_overrides application in Subscriptions\CreateService —
        // intent-port of Rails' Plans::OverrideService below).
        $sub2ExternalId = $subExternalId.'-2';
        $startedAt2 = $startedAt->clone()->addDays(4)->addHour()->addMinutes(13);

        $hasSub2 = Subscription::query()
            ->where('customer_id', $johnDoe->id)
            ->where('external_id', $sub2ExternalId)
            ->where('status', SubscriptionStatus::Active->value)
            ->exists();

        if (! $hasSub2) {
            $subscription = CreateService::callBang(
                customer: $johnDoe,
                plan: $plan,
                params: [
                    'name' => 'Subscription With Plan Override',
                    'billing_time' => 'calendar',
                    'subscription_at' => $startedAt2,
                    'started_at' => $startedAt2,
                    'external_id' => $sub2ExternalId,
                    'plan_overrides' => [
                        'name' => 'Premium with Override',
                        'description' => 'This plan is used to test the override functionality',
                        'amount_cents' => 211_00,
                    ],
                ],
            )->subscription;

            // Rails' Plans::OverrideService: duplicate the plan under
            // parent_id, apply the overrides and copy the charges; the new
            // subscription targets the override plan.
            $override = $plan->replicate();
            $override->parent_id = $plan->id;
            $override->name = 'Premium with Override';
            $override->description = 'This plan is used to test the override functionality';
            $override->amount_cents = 211_00;
            $override->save();

            foreach ($plan->charges as $charge) {
                $chargeCopy = $charge->replicate();
                $chargeCopy->plan_id = $override->id;
                $chargeCopy->save();
            }

            $subscription->plan_id = $override->id;
            $subscription->started_at = $startedAt2;
            $subscription->created_at = $startedAt2;
            $subscription->save();
        }
    }
}
