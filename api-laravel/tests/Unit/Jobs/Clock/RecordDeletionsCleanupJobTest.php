<?php

declare(strict_types=1);

use App\Models\RecordDeletion;
use App\Jobs\Clock\RecordDeletionsCleanupJob;

uses()->group('ledger:job:Clock.RecordDeletionsCleanupJob');

/**
 * Port of Rails' spec/jobs/clock/record_deletions_cleanup_job_spec.rb —
 * the daily purge removes tombstones older than the retention period in
 * batches.
 */
it('removes tombstones older than the retention period', function (): void {
    RecordDeletion::factory()->create(['deleted_at' => now()->subMonths(2)]);

    expect(RecordDeletion::query()->count())->toBe(1);

    (new RecordDeletionsCleanupJob)->handle();

    expect(RecordDeletion::query()->count())->toBe(0);
});

it('keeps tombstones newer than the retention period', function (): void {
    RecordDeletion::factory()->create(['deleted_at' => now()->subWeeks(3)]);

    (new RecordDeletionsCleanupJob)->handle();

    expect(RecordDeletion::query()->count())->toBe(1);
});

it('removes more expired tombstones than one batch', function (): void {
    RecordDeletionsCleanupJob::$batchSize = 2;

    RecordDeletion::factory()->count(3)->create(['deleted_at' => now()->subMonths(2)]);

    (new RecordDeletionsCleanupJob)->handle();

    expect(RecordDeletion::query()->count())->toBe(0);

    RecordDeletionsCleanupJob::$batchSize = 10000;
});
