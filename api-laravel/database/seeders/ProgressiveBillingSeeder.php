<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' db/seeds/08_progressive_billing.rb: usage thresholds on
 * the premium plan (via UsageThresholds::UpdateService upstream) and on the
 * john-doe subscription (via Subscriptions::UpdateUsageThresholdsService).
 *
 * Neither service is ported yet — the seeder writes the frozen
 * `usage_thresholds` table directly (delete + insert, like the upstream
 * `partial: false` semantics).
 */
class ProgressiveBillingSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('name', 'Hooli')->firstOrFail();
        $plan = Plan::query()->where('organization_id', $organization->id)
            ->where('code', 'premium_plan')->firstOrFail();
        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->where('external_id', 'sub_john-doe-main')->firstOrFail();

        $now = now();

        // Plan-level thresholds.
        DB::table('usage_thresholds')->where('plan_id', $plan->id)->delete();
        DB::table('usage_thresholds')->insert([
            [
                'organization_id' => $organization->id,
                'plan_id' => $plan->id,
                'threshold_display_name' => 'Initial Threshold',
                'amount_cents' => 12000,
                'recurring' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'organization_id' => $organization->id,
                'plan_id' => $plan->id,
                'threshold_display_name' => 'Recurring Threshold',
                'amount_cents' => 100000,
                'recurring' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Subscription-level overrides.
        DB::table('usage_thresholds')->where('subscription_id', $subscription->id)->delete();
        DB::table('usage_thresholds')->insert([
            [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'threshold_display_name' => 'Initial Threshold',
                'amount_cents' => 40000,
                'recurring' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'threshold_display_name' => null,
                'amount_cents' => 80000,
                'recurring' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'threshold_display_name' => null,
                'amount_cents' => 200000,
                'recurring' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
