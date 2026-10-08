<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use Illuminate\Bus\Queueable;
use App\Models\RecordDeletion;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::RecordDeletionsCleanupJob
 * (app/jobs/clock/record_deletions_cleanup_job.rb) — the daily purge of
 * tombstone rows older than the 1-month retention period
 * (`schedule:clean_record_deletions`, clock.rb at: "01:20").
 */
class RecordDeletionsCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rails: BATCH_SIZE = 10_000. */
    public static int $batchSize = 10000;

    /** Rails: RETENTION_PERIOD = 1.month. */
    public static int $retentionDays = 30;

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
            $deleted = RecordDeletion::query()
                ->whereIn('id', function ($query): void {
                    $query->select('id')
                        ->from((new RecordDeletion)->getTable())
                        ->where('deleted_at', '<', now()->subDays(self::$retentionDays))
                        ->limit(self::$batchSize);
                })
                ->delete();
        } while ($deleted >= self::$batchSize);
    }
}
