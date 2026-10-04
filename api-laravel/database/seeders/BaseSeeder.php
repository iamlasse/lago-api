<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tax;
use App\Models\Role;
use App\Models\User;
use App\Models\AddOn;
use App\Models\ApiKey;
use App\Models\AddOnTax;
use App\Models\Membership;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\MembershipRole;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Services\BillableMetrics\CreateService;
use App\Services\Taxes\CreateService as TaxCreateService;
use App\Services\Plans\CreateService as PlanCreateService;
use App\Services\Coupons\CreateService as CouponCreateService;

/**
 * Port of Rails' db/seeds/01_base.rb: roles, users, the Hooli organization,
 * billing entity, memberships, api keys and the base catalog (billable
 * metrics, tax, add-ons, coupons, plans, pricing unit).
 *
 * Idempotent like the Rails source: find-or-create on natural keys, api keys
 * destroyed and recreated on every run.
 */
class BaseSeeder extends Seeder
{
    /** Rails Organization::PREMIUM_INTEGRATIONS (app/models/organization.rb). */
    private const PREMIUM_INTEGRATIONS = [
        'beta_payment_authorization', 'netsuite', 'okta', 'entra_id', 'avalara',
        'xero', 'progressive_billing', 'lifetime_usage', 'hubspot', 'auto_dunning',
        'revenue_analytics', 'salesforce', 'api_permissions', 'revenue_share',
        'remove_branding_watermark', 'manual_payments', 'from_email',
        'issue_receipts', 'preview', 'multi_entities_pro', 'multi_entities_enterprise',
        'analytics_dashboards', 'forecasted_usage', 'projected_usage', 'custom_roles',
        'events_targeting_wallets', 'security_logs', 'granular_lifetime_usage',
        'order_forms', 'revenue_recognition',
    ];

    /**
     * Rails' custom `accountant` role: Permission::DATA keys whose roles
     * include "finance" or "manager" (computed from api/config/permissions.yml).
     */
    private const ACCOUNTANT_PERMISSIONS = [
        'addons:view', 'analytics:view', 'billing_entities:view',
        'billing_entities:create', 'billing_entities:update', 'billing_entities:delete',
        'coupons:view', 'coupons:attach', 'coupons:detach', 'credit_notes:view',
        'credit_notes:create', 'credit_notes:update', 'credit_notes:void',
        'credit_notes:export', 'credit_notes:send', 'customers:view',
        'customers:create', 'customers:update', 'customers:delete',
        'data_api:view', 'dunning_campaigns:view', 'dunning_campaigns:create',
        'dunning_campaigns:update', 'dunning_campaigns:delete', 'invoices:view',
        'invoices:send', 'invoices:create', 'invoices:update', 'invoices:void',
        'invoices:export', 'invoice_custom_sections:view',
        'invoice_custom_sections:create', 'invoice_custom_sections:update',
        'invoice_custom_sections:delete', 'quotes:view', 'quotes:create',
        'quotes:update', 'quotes:approve', 'quotes:clone', 'quotes:void',
        'order_forms:view', 'order_forms:sign', 'order_forms:void', 'orders:view',
        'orders:update', 'orders:execute', 'organization:view',
        'organization:update', 'organization:invoices:view',
        'organization:invoices:update', 'organization:integrations:view',
        'organization:integrations:create', 'organization:integrations:update',
        'organization:integrations:delete', 'payments:view', 'payments:create',
        'payment_receipts:view', 'payment_receipts:send', 'plans:view',
        'pricing_units:view', 'subscriptions:view', 'subscriptions:create',
        'subscriptions:update', 'wallets:create', 'wallets:update',
        'wallets:top_up', 'wallets:terminate',
    ];

    public function run(): void
    {
        // == Roles (predefined, organization-less)
        $adminRole = Role::firstOrCreate(
            ['code' => 'admin', 'organization_id' => null],
            ['admin' => true, 'name' => 'Admin', 'description' => 'Administrator having all permissions'],
        );
        $financeRole = Role::firstOrCreate(
            ['code' => 'finance', 'organization_id' => null],
            ['name' => 'Finance', 'description' => 'Finance role with permissions to manage financial data'],
        );
        Role::firstOrCreate(
            ['code' => 'manager', 'organization_id' => null],
            ['name' => 'Manager', 'description' => 'The predefined manager role'],
        );

        // == Users
        $gavin = User::firstOrCreate(['email' => 'gavin@hooli.com'], ['password' => 'ILoveLago']);
        $dinesh = User::firstOrCreate(['email' => 'dinesh@hooli.com'], ['password' => 'ILoveLago']);

        // == Organizations (the ClickHouse second org is out of scope: the
        // port has no ClickHouse event store).
        $organization = Organization::query()->where('name', 'Hooli')->first()
            ?? Organization::factory()->create([
                'id' => '11111111-2222-3333-4444-555555555555',
                'name' => 'Hooli',
            ]);

        $organization->update([
            'premium_integrations' => self::PREMIUM_INTEGRATIONS,
            'invoice_footer' => 'Hooli is a fictional company.',
        ]);

        $billingEntity = BillingEntity::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Hooli', 'code' => 'hooli'],
        );
        $billingEntity->update([
            'email' => 'gavin@hooli.com',
            'email_settings' => BillingEntity::EMAIL_SETTINGS,
        ]);

        // == Memberships + roles
        $membership = Membership::firstOrCreate(
            ['user_id' => $gavin->id, 'organization_id' => $organization->id],
            ['status' => 0],
        );
        MembershipRole::firstOrCreate([
            'membership_id' => $membership->id,
            'organization_id' => $organization->id,
            'role_id' => $adminRole->id,
        ]);

        $dineshMembership = Membership::firstOrCreate(
            ['user_id' => $dinesh->id, 'organization_id' => $organization->id],
            ['status' => 0],
        );
        MembershipRole::firstOrCreate([
            'membership_id' => $dineshMembership->id,
            'organization_id' => $organization->id,
            'role_id' => $financeRole->id,
        ]);

        // Custom role combining finance and manager permissions.
        Role::firstOrCreate(
            ['code' => 'accountant', 'organization_id' => $organization->id],
            [
                'name' => 'Accountant',
                'description' => 'Custom role combining finance and manager permissions',
                'permissions' => self::ACCOUNTANT_PERMISSIONS,
            ],
        );

        // == Anrok integration (no model in the port — raw insert).
        $anrokExists = DB::table('integrations')
            ->where('organization_id', $organization->id)
            ->where('code', 'anrok')
            ->exists();

        if (! $anrokExists) {
            DB::table('integrations')->insert([
                'organization_id' => $organization->id,
                'code' => 'anrok',
                'name' => 'Anrok Integration',
                'type' => 'anrok',
                'secrets' => json_encode([
                    'connection_id' => (string) Str::uuid(),
                    'api_key' => (string) Str::uuid(),
                ]),
                'settings' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // In development, a webhook endpoint to the local webhook-tester
        // service (Rails: only in Rails.env.development? — the port's seeds
        // always run in a dev-like context).
        WebhookEndpoint::firstOrCreate([
            'organization_id' => $organization->id,
            'webhook_url' => 'http://webhook/'.$organization->id,
        ]);

        // == Api keys — destroyed and recreated on every run (Rails
        // `update_columns` port: the value is forced past the creating hook).
        $organization->apiKeys()->delete();

        $organization->apiKeys()->create([
            'name' => 'Expired Key',
            'expires_at' => now()->subDay(),
            'last_used_at' => now()->subHours(36),
            'permissions' => ['customer' => ['read', 'write']],
        ]);

        $hooliKey = $organization->apiKeys()->create([
            'name' => 'Hooli Key',
            'permissions' => ApiKey::defaultPermissions(),
        ]);
        DB::table('api_keys')->where('id', $hooliKey->id)->update([
            'value' => 'lago_key-hooli-1234567890',
        ]);

        // == Billable metrics
        $sumBm = $this->findMetric($organization->id, 'sum_bm')
            ?? CreateService::callBang(args: [
                'organization_id' => $organization->id,
                'aggregation_type' => 'sum_agg',
                'name' => 'Sum BM',
                'code' => 'sum_bm',
                'field_name' => 'custom_field',
            ])->billable_metric;

        $countBm = $this->findMetric($organization->id, 'count_bm')
            ?? CreateService::callBang(args: [
                'organization_id' => $organization->id,
                'aggregation_type' => 'count_agg',
                'name' => 'Count BM',
                'code' => 'count_bm',
            ])->billable_metric;

        // == Taxes
        $frTax = Tax::query()->where('organization_id', $organization->id)
            ->where('code', 'lago_eu_fr_standard')->first()
            ?? TaxCreateService::callBang(
                organization: $organization,
                params: [
                    'name' => 'FR Standard',
                    'code' => 'lago_eu_fr_standard',
                    'description' => 'FR Standard',
                    'rate' => 20,
                ],
            )->tax;

        // == Add-ons (no AddOns::CreateService in the port — factory + join).
        $setupFee = AddOn::query()->where('organization_id', $organization->id)
            ->where('code', 'setup_fee')->first();

        if ($setupFee === null) {
            $setupFee = AddOn::factory()->for($organization)->create([
                'name' => 'Setup Fee',
                'code' => 'setup_fee',
                'description' => 'Fee for setting up the subscription',
                'amount_cents' => 100_00,
                'amount_currency' => 'EUR',
            ]);
            AddOnTax::create(['add_on_id' => $setupFee->id, 'tax_id' => $frTax->id, 'organization_id' => $organization->id]);
        }

        // NOTE: Rails' second block re-checks `setup_fee` (upstream bug) but
        // creates support_hour — port the intent, keep the same net effect.
        $supportHour = AddOn::query()->where('organization_id', $organization->id)
            ->where('code', 'support_hour')->first();

        if ($supportHour === null) {
            $supportHour = AddOn::factory()->for($organization)->create([
                'name' => 'Hour of Premium Support',
                'code' => 'support_hour',
                'description' => 'One hour of support from our experts',
                'amount_cents' => 84_99,
                'amount_currency' => 'EUR',
            ]);
            AddOnTax::create(['add_on_id' => $supportHour->id, 'tax_id' => $frTax->id, 'organization_id' => $organization->id]);
        }

        // == Coupons
        if (! $this->couponExists($organization->id, '20_percent_off')) {
            CouponCreateService::callBang(args: [
                'organization_id' => $organization->id,
                'name' => '20% off',
                'code' => '20_percent_off',
                'coupon_type' => 'percentage',
                'percentage_rate' => 20,
                'frequency' => 'forever',
                'expiration' => 'no_expiration',
            ]);
        }

        if (! $this->couponExists($organization->id, '10_euro_off')) {
            CouponCreateService::callBang(args: [
                'organization_id' => $organization->id,
                'name' => '10€ off',
                'code' => '10_euro_off',
                'coupon_type' => 'fixed_amount',
                'amount_cents' => 1000,
                'amount_currency' => 'EUR',
                'frequency' => 'forever',
                'expiration' => 'no_expiration',
            ]);
        }

        // == Plans
        if (! $this->planExists($organization->id, 'standard_plan')) {
            PlanCreateService::callBang(args: [
                'organization_id' => $organization->id,
                'name' => 'Standard Plan',
                'code' => 'standard_plan',
                'interval' => 'monthly',
                'pay_in_advance' => true,
                'amount_cents' => 19_99,
                'amount_currency' => 'EUR',
                'tax_codes' => ['lago_eu_fr_standard'],
                'charges' => [
                    [
                        'billable_metric_id' => $sumBm->id,
                        'charge_model' => 'standard',
                        'amount_currency' => 'EUR',
                        'pay_in_advance' => false,
                        'properties' => ['amount' => '100'],
                    ],
                    [
                        'billable_metric_id' => $countBm->id,
                        'charge_model' => 'standard',
                        'amount_currency' => 'EUR',
                        'pay_in_advance' => false,
                        'properties' => ['amount' => '499'],
                    ],
                ],
            ], sendWebhook: false);
        }

        if (! $this->planExists($organization->id, 'premium_plan')) {
            PlanCreateService::callBang(args: [
                'organization_id' => $organization->id,
                'name' => 'Premium Plan',
                'code' => 'premium_plan',
                'interval' => 'monthly',
                'pay_in_advance' => true,
                'amount_cents' => 100_00,
                'amount_currency' => 'EUR',
                'tax_codes' => ['lago_eu_fr_standard'],
                'charges' => [
                    [
                        'billable_metric_id' => $sumBm->id,
                        'charge_model' => 'standard',
                        'amount_currency' => 'EUR',
                        'pay_in_advance' => false,
                        'properties' => ['amount' => '30'],
                    ],
                    [
                        'billable_metric_id' => $countBm->id,
                        'charge_model' => 'standard',
                        'amount_currency' => 'EUR',
                        'pay_in_advance' => false,
                        'properties' => ['amount' => '399'],
                    ],
                ],
            ], sendWebhook: false);
        }

        // == Pricing unit (no model/service in the port — raw insert).
        DB::table('pricing_units')->insertOrIgnore([
            'organization_id' => $organization->id,
            'name' => 'xyz',
            'code' => 'xyz',
            'short_name' => 'XYZ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function findMetric(string $organizationId, string $code): ?object
    {
        return DB::table('billable_metrics')
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->first();
    }

    private function couponExists(string $organizationId, string $code): bool
    {
        return DB::table('coupons')
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->exists();
    }

    private function planExists(string $organizationId, string $code): bool
    {
        return DB::table('plans')
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->exists();
    }
}
