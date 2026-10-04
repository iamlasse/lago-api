<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\Membership;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\MembershipRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\BillableMetricFilter;
use App\Services\BillableMetrics\CreateService;

/**
 * Port of Rails' db/seeds/30_product_catalog.rb: the "Hooli v2"
 * organization with the product-catalog feature flag and its v2 catalog
 * (category, products, filters, rate cards, catalog plan).
 *
 * The product-catalog models/services are not ported yet — those rows go
 * through raw inserts against the frozen tables. NOTE: the frozen schema has
 * no product_filter_values-style table, so the `eu_traffic` filter's values
 * (billable_metric_filter link + value) are represented by the product
 * filter row alone plus the billable metric's region filter.
 */
class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $gavin = User::query()->where('email', 'gavin@hooli.com')->firstOrFail();

        // == Organization "Hooli v2"
        $organization = Organization::query()->where('name', 'Hooli v2')->first()
            ?? Organization::factory()->create([
                'id' => '33333333-4444-5555-6666-777777777777',
                'name' => 'Hooli v2',
            ]);

        $featureFlags = (array) ($organization->feature_flags ?? []);
        $organization->update([
            'invoice_footer' => 'Hooli v2 is a fictional company on the product catalog.',
            'feature_flags' => array_values(array_unique(array_merge($featureFlags, ['product_catalog']))),
        ]);

        // == Billing entity, membership, api key
        $billingEntity = DB::table('billing_entities')
            ->where('organization_id', $organization->id)
            ->where('code', 'hooli_v2')->first();

        if ($billingEntity === null) {
            DB::table('billing_entities')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'name' => 'Hooli v2',
                'code' => 'hooli_v2',
                'email' => 'gavin@hooli.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $membership = Membership::firstOrCreate(
            ['user_id' => $gavin->id, 'organization_id' => $organization->id],
            ['status' => 0],
        );
        $adminRole = Role::query()->where('admin', true)->firstOrFail();
        MembershipRole::firstOrCreate([
            'membership_id' => $membership->id,
            'organization_id' => $organization->id,
            'role_id' => $adminRole->id,
        ]);

        $hasApiKeys = DB::table('api_keys')->where('organization_id', $organization->id)->exists();

        if (! $hasApiKeys) {
            $key = $organization->apiKeys()->create(['name' => 'Hooli v2 Key']);
            DB::table('api_keys')->where('id', $key->id)->update([
                'value' => 'lago_key-hooli-v2-1234567890',
            ]);
        }

        // == Catalog
        $catalogExists = DB::table('product_categories')
            ->where('organization_id', $organization->id)
            ->where('code', 'cloud_platform')->exists();

        if ($catalogExists) {
            return;
        }

        // Billable metric with a region filter (the CreateService's filters
        // arg is TODO(port) — the filter is attached via the model).
        $metricId = DB::table('billable_metrics')
            ->where('organization_id', $organization->id)
            ->where('code', 'catalog_api_calls')->value('id');

        if ($metricId === null) {
            $metric = CreateService::callBang(args: [
                'organization_id' => $organization->id,
                'aggregation_type' => 'count_agg',
                'name' => 'API calls',
                'code' => 'catalog_api_calls',
            ])->billable_metric;
            $metricId = $metric->id;

            BillableMetricFilter::create([
                'billable_metric_id' => $metricId,
                'organization_id' => $organization->id,
                'key' => 'region',
                'values' => ['us', 'eu'],
            ]);
        }

        // == Product category
        $categoryId = (string) Str::uuid();
        DB::table('product_categories')->insert([
            'id' => $categoryId,
            'organization_id' => $organization->id,
            'code' => 'cloud_platform',
            'name' => 'Cloud Platform',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // == Products
        $usageProductId = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $usageProductId,
            'organization_id' => $organization->id,
            'product_category_id' => $categoryId,
            'billable_metric_id' => $metricId,
            'product_type' => 'metered',
            'code' => 'api_calls',
            'name' => 'API calls',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fixedProductId = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $fixedProductId,
            'organization_id' => $organization->id,
            'product_category_id' => $categoryId,
            'product_type' => 'fixed',
            'code' => 'platform_fee',
            'name' => 'Platform fee',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // == Product filter on the usage product
        $filterId = (string) Str::uuid();
        DB::table('product_filters')->insert([
            'id' => $filterId,
            'organization_id' => $organization->id,
            'product_id' => $usageProductId,
            'code' => 'eu_traffic',
            'name' => 'EU traffic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // == Rate card + rate
        $rateCardId = (string) Str::uuid();
        DB::table('rate_cards')->insert([
            'id' => $rateCardId,
            'organization_id' => $organization->id,
            'product_id' => $usageProductId,
            'code' => 'standard_usd',
            'name' => 'Standard USD',
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rate_card_rates')->insert([
            'organization_id' => $organization->id,
            'rate_card_id' => $rateCardId,
            'code' => 'rate_1',
            'effective_from' => now()->subMonth()->startOfDay(),
            'rate_model' => 'standard',
            'rate_properties' => json_encode(['amount' => '0.01']),
            'billing_interval_unit' => 'month',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // == Catalog plan + rate card attachment
        $catalogPlanId = (string) Str::uuid();
        DB::table('catalog_plans')->insert([
            'id' => $catalogPlanId,
            'organization_id' => $organization->id,
            'code' => 'growth',
            'name' => 'Growth',
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('plan_rate_cards')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'catalog_plan_id' => $catalogPlanId,
            'rate_card_id' => $rateCardId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
