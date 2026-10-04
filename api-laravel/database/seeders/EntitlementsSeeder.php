<?php

declare(strict_types=1);

namespace Database\Seeders;

use stdClass;
use App\Models\Plan;
use App\Support\License;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' db/seeds/05_entitlements.rb.
 *
 * The entitlement models are not ported yet, so the seeder writes the frozen
 * tables directly (inserts mirror the upstream rows, including the
 * deliberately staggered created_at values). Delete-then-recreate per
 * feature mirrors Rails' clean_up_feature!.
 */
class EntitlementsSeeder extends Seeder
{
    public function run(): void
    {
        // Rails: return unless License.premium?
        if (! License::premium()) {
            return;
        }

        $organization = Organization::query()->where('name', 'Hooli')->firstOrFail();
        $plan = Plan::query()->where('organization_id', $organization->id)
            ->where('code', 'premium_plan')->firstOrFail();
        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->where('external_id', 'sub_john-doe-main')->firstOrFail();

        $now = now();

        // == seats — feature with privileges and subscription overrides
        $seats = $this->cleanUpFeature($organization->id, 'seats', 'Number of seats', $now->copy()->subMinutes(20));

        $max = $this->createPrivilege($organization->id, $seats, 'max', 'integer', $now->copy()->subMinutes(20));
        $maxAdmins = $this->createPrivilege($organization->id, $seats, 'max_admins', 'integer', $now->copy()->subMinutes(10));
        $root = $this->createPrivilege($organization->id, $seats, 'root', 'boolean', $now->copy()->subMinutes(15));

        $seatsPlanEntitlement = $this->createEntitlement($organization->id, $seats, planId: $plan->id, createdAt: $now->copy()->subHour());
        $this->createValue($organization->id, $max, $seatsPlanEntitlement, '20', $now->copy()->subHour());
        $this->createValue($organization->id, $maxAdmins, $seatsPlanEntitlement, '3000', $now->copy()->subMinutes(9), deletedAt: $now);
        $this->createValue($organization->id, $maxAdmins, $seatsPlanEntitlement, '3', $now->copy()->subMinutes(8));

        $seatsSubEntitlement = $this->createEntitlement($organization->id, $seats, subscriptionId: $subscription->id, createdAt: $now->copy()->subMinutes(30));
        $this->createValue($organization->id, $max, $seatsSubEntitlement, '99');
        $this->createValue($organization->id, $root, $seatsSubEntitlement, 'true');

        // == analytics_api — plan entitlement only, no privileges
        $analyticsApi = $this->cleanUpFeature($organization->id, 'analytics_api', 'Analytics API', $now->copy()->subMinutes(20));
        $this->createEntitlement($organization->id, $analyticsApi, planId: $plan->id, createdAt: $now->copy()->subYear());

        // == acls — plan entitlement, soft-deleted
        $acls = $this->cleanUpFeature($organization->id, 'acls', 'Granular permissions', $now->copy()->subMinutes(20));
        $this->createEntitlement($organization->id, $acls, planId: $plan->id, createdAt: $now->copy()->subMinutes(20), deletedAt: $now);

        // == salesforce — subscription-only entitlement
        $salesforce = $this->cleanUpFeature($organization->id, 'salesforce', 'Salesforce Integration', $now->copy()->subMinutes(20));
        $this->createEntitlement($organization->id, $salesforce, subscriptionId: $subscription->id, createdAt: $now->copy()->subMinutes(20));

        // == premium_support — plan + subscription entitlements + removal
        $premiumSupport = $this->cleanUpFeature($organization->id, 'premium_support', 'Premium Support', $now->copy()->subMinutes(20));
        $this->createEntitlement($organization->id, $premiumSupport, planId: $plan->id, createdAt: $now->copy()->subMinutes(20));
        $this->createEntitlement($organization->id, $premiumSupport, subscriptionId: $subscription->id, createdAt: $now->copy()->subMinutes(20));
        $this->createRemoval($organization->id, $premiumSupport, $subscription->id);

        // == sso — select privilege, plan + subscription values, removal
        $sso = $this->cleanUpFeature($organization->id, 'sso', 'SSO', $now->copy()->subMinutes(20));
        $provider = $this->createPrivilege($organization->id, $sso, 'provider', 'select', $now->copy()->subMinutes(20), [
            'select_options' => ['okta', 'ad', 'google', 'custom'],
        ]);
        $ssoPlanEntitlement = $this->createEntitlement($organization->id, $sso, planId: $plan->id, createdAt: $now->copy()->subDays(10));
        $this->createValue($organization->id, $provider, $ssoPlanEntitlement, 'okta', $now->copy()->subDays(10));
        $ssoSubEntitlement = $this->createEntitlement($organization->id, $sso, subscriptionId: $subscription->id, createdAt: $now->copy()->subDay());
        $this->createValue($organization->id, $provider, $ssoSubEntitlement, 'google', $now->copy()->subDay());
        $ssoRemoval = $this->createRemoval($organization->id, $sso, $subscription->id);
        DB::table('entitlement_subscription_feature_removals')
            ->where('id', $ssoRemoval)
            ->update(['deleted_at' => $now]);
    }

    /**
     * Rails clean_up_feature!: hard-delete every entitlement row touching
     * the feature (discarded or not), then recreate it.
     */
    private function cleanUpFeature(string $organizationId, string $code, string $name, \Carbon\CarbonInterface $createdAt): object
    {
        $feature = DB::table('entitlement_features')
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->first();

        if ($feature !== null) {
            $privilegeIds = DB::table('entitlement_privileges')
                ->where('entitlement_feature_id', $feature->id)
                ->pluck('id');

            DB::table('entitlement_subscription_feature_removals')
                ->where(function ($q) use ($feature, $privilegeIds): void {
                    $q->where('entitlement_feature_id', $feature->id)
                        ->orWhereIn('entitlement_privilege_id', $privilegeIds);
                })
                ->delete();
            DB::table('entitlement_entitlement_values')->whereIn('entitlement_privilege_id', $privilegeIds)->delete();
            DB::table('entitlement_entitlements')->where('entitlement_feature_id', $feature->id)->delete();
            DB::table('entitlement_privileges')->where('entitlement_feature_id', $feature->id)->delete();
        } else {
            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('entitlement_features')->insert([
                'id' => $id,
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => $name,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $feature = DB::table('entitlement_features')->where('id', $id)->first();
        }

        return $feature;
    }

    private function createPrivilege(string $organizationId, object $feature, string $code, string $valueType, \Carbon\CarbonInterface $createdAt, array $config = []): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('entitlement_privileges')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'entitlement_feature_id' => $feature->id,
            'code' => $code,
            'value_type' => $valueType,
            'config' => json_encode($config ?: new stdClass()),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function createEntitlement(string $organizationId, object $feature, ?string $planId = null, ?string $subscriptionId = null, ?\Carbon\CarbonInterface $createdAt = null, ?\Carbon\CarbonInterface $deletedAt = null): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('entitlement_entitlements')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'entitlement_feature_id' => $feature->id,
            'plan_id' => $planId,
            'subscription_id' => $subscriptionId,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
            'deleted_at' => $deletedAt,
        ]);

        return $id;
    }

    private function createValue(string $organizationId, string $privilegeId, string $entitlementId, string $value, ?\Carbon\CarbonInterface $createdAt = null, ?\Carbon\CarbonInterface $deletedAt = null): void
    {
        DB::table('entitlement_entitlement_values')->insert([
            'organization_id' => $organizationId,
            'entitlement_privilege_id' => $privilegeId,
            'entitlement_entitlement_id' => $entitlementId,
            'value' => $value,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
            'deleted_at' => $deletedAt,
        ]);
    }

    private function createRemoval(string $organizationId, object $feature, string $subscriptionId): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('entitlement_subscription_feature_removals')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'entitlement_feature_id' => $feature->id,
            'subscription_id' => $subscriptionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
