<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Support\Metrics\MetricsSink;
use Illuminate\Support\ServiceProvider;
use App\Support\Metrics\NullMetricsSink;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Services\Wallets\Buckets\UsageBucketReadiness;
use App\Services\Wallets\Buckets\PendingUsageBucketReadiness;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Wallets\RealtimeRefreshService's bucket watermark read — the
        // ClickHouse usage bucket sink is not ported yet, so the default
        // reports every bucket as caught up (TODO(port): swap for the
        // real Clickhouse::UsageBucket query).
        $this->app->bind(UsageBucketReadiness::class, PendingUsageBucketReadiness::class);

        // Yabeda stand-in: the Prometheus exporter is not ported, so the
        // realtime-usage metrics discard until a sink exists.
        $this->app->bind(MetricsSink::class, NullMetricsSink::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Polymorphic type columns store Rails class names (payments.
        // payable_type = "Invoice" / "PaymentRequest") — map them to the
        // Laravel classes so morphTo relations resolve.
        Relation::morphMap([
            'Invoice' => Invoice::class,
            'PaymentRequest' => PaymentRequest::class,
        ]);
    }
}
