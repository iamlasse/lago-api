<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::WebhooksCleanupJob
 * (app/jobs/clock/webhooks_cleanup_job.rb) — the daily purge of webhooks
 * older than the 90-day retention period.
 *
 * NOTE (ported from Rails): manual batching is used instead of chunked
 * deletes because the table can contain millions of rows — a plain
 * `limit` subquery lets PostgreSQL use the covering index on
 * `(updated_at) INCLUDE (id)`, which an ordered chunk iteration would not.
 *
 * NOTE: this only removes the database rows. The payload/response blobs
 * stored on object storage under `webhooks/<date>/<uuid>/` (see
 * Webhook#storePayload) are NOT deleted here — configure a bucket lifecycle
 * rule to delete blobs older than the retention period.
 */
class WebhooksCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rails: class_attribute :batch_size, default: 1_000. */
    public static int $batchSize = 1000;

    /** Rails: class_attribute :retention_period, default: 90.days. */
    public static int $retentionDays = 90;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 20 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        do {
            $deleted = Webhook::query()
                ->whereIn('id', function ($query): void {
                    $query->select('id')
                        ->from((new Webhook)->getTable())
                        ->where('updated_at', '<', now()->subDays(self::$retentionDays))
                        ->limit(self::$batchSize);
                })
                ->delete();
        } while ($deleted >= self::$batchSize);
    }
}
