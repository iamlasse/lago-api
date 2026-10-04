<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\License;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' db/seeds/06_alerting.rb: usage monitoring alerts and
 * triggered alerts for the john-doe subscription.
 *
 * The usage-monitoring models are not ported yet, so the seeder writes the
 * frozen tables directly. SendWebhookJob('alert.triggered') is NOT ported
 * (unregistered event in SendWebhookJob::WEBHOOK_SERVICES) — TODO(port).
 */
class AlertingSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('name', 'Hooli')->firstOrFail();
        $sumBm = DB::table('billable_metrics')
            ->where('organization_id', $organization->id)
            ->where('code', 'sum_bm')->first();
        $subscription = DB::table('subscriptions')
            ->where('organization_id', $organization->id)
            ->where('external_id', 'sub_john-doe-main')->first();

        // Idempotency: delete derived rows first (Rails order).
        $existingAlerts = DB::table('usage_monitoring_alerts')
            ->where('organization_id', $organization->id)
            ->where('subscription_external_id', $subscription->external_id)
            ->pluck('id');

        DB::table('usage_monitoring_triggered_alerts')->whereIn('usage_monitoring_alert_id', $existingAlerts)->delete();
        DB::table('usage_monitoring_alert_thresholds')->whereIn('usage_monitoring_alert_id', $existingAlerts)->delete();
        DB::table('usage_monitoring_alerts')->whereIn('id', $existingAlerts)->delete();

        // == current_usage_amount "default"
        $defaultAlert = $this->createAlert($organization->id, 'current_usage_amount', 'default', 'Default Alert', $subscription->external_id);
        $this->createThreshold($organization->id, $defaultAlert, 'warn', 8000);
        $this->createThreshold($organization->id, $defaultAlert, 'alert', 10000);
        $this->createThreshold($organization->id, $defaultAlert, 'panic', 3300, recurring: true);

        // == lifetime_usage_amount "total" (premium only)
        if (License::premium()) {
            $totalAlert = $this->createAlert($organization->id, 'lifetime_usage_amount', 'total', null, $subscription->external_id);
            $this->createThreshold($organization->id, $totalAlert, 'info', 100000);

            DB::table('usage_monitoring_triggered_alerts')->insert([
                [
                    'organization_id' => $organization->id,
                    'usage_monitoring_alert_id' => $totalAlert,
                    'subscription_id' => $subscription->id,
                    'current_value' => 51,
                    'previous_value' => 8,
                    'crossed_thresholds' => json_encode([
                        ['code' => null, 'value' => 10],
                        ['code' => 'warn', 'value' => 25],
                        ['code' => 'alert', 'value' => 50],
                    ]),
                    'triggered_at' => now()->subMonths(2),
                    'created_at' => now()->subMonths(2),
                    'updated_at' => now()->subMonths(2),
                ],
                [
                    'organization_id' => $organization->id,
                    'usage_monitoring_alert_id' => $totalAlert,
                    'subscription_id' => $subscription->id,
                    'current_value' => 88,
                    'previous_value' => 234,
                    'crossed_thresholds' => json_encode([
                        ['code' => 'alert', 'value' => 100],
                        ['code' => 'alert', 'value' => 150, 'recurring' => true],
                        ['code' => 'alert', 'value' => 200, 'recurring' => true],
                    ]),
                    'triggered_at' => now()->subDays(11),
                    'created_at' => now()->subDays(11),
                    'updated_at' => now()->subDays(11),
                ],
            ]);
        }

        // == billable_metric_current_usage_amount "ops" on sum_bm
        $opsAlert = $this->createAlert($organization->id, 'billable_metric_current_usage_amount', 'ops', 'Operations Alert', $subscription->external_id, $sumBm->id);
        $this->createThreshold($organization->id, $opsAlert, null, 5000);
        $this->createThreshold($organization->id, $opsAlert, null, 1000, recurring: true);

        DB::table('usage_monitoring_triggered_alerts')->insert([
            'organization_id' => $organization->id,
            'usage_monitoring_alert_id' => $opsAlert,
            'subscription_id' => $subscription->id,
            'current_value' => 8,
            'previous_value' => 0,
            'crossed_thresholds' => json_encode([
                ['code' => null, 'value' => 5],
            ]),
            'triggered_at' => now()->subDays(4),
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(4),
        ]);
    }

    private function createAlert(string $organizationId, string $alertType, string $code, ?string $name, string $subscriptionExternalId, ?string $billableMetricId = null): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('usage_monitoring_alerts')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'alert_type' => $alertType,
            'code' => $code,
            'name' => $name,
            'subscription_external_id' => $subscriptionExternalId,
            'billable_metric_id' => $billableMetricId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createThreshold(string $organizationId, string $alertId, ?string $code, int $value, bool $recurring = false): void
    {
        DB::table('usage_monitoring_alert_thresholds')->insert([
            'organization_id' => $organizationId,
            'usage_monitoring_alert_id' => $alertId,
            'code' => $code,
            'value' => $value,
            'recurring' => $recurring,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
