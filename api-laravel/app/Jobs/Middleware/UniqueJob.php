<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use ReflectionClass;
use Illuminate\Support\Facades\Cache;

/**
 * Port of Rails/Sidekiq-ent unique jobs (`unique :until_executed,
 * on_conflict: :log, lock_ttl: ...`).
 *
 * Laravel's ShouldBeUnique alone is insufficient: it releases the job when
 * the lock is taken instead of logging and skipping, and its lock has no
 * per-argument key override. This middleware wraps the job in a cache lock
 * keyed by the job's `uniqueKey()` (jobs porting `lock_key_arguments`
 * override that method — the lock key construction must match Rails
 * EXACTLY, a wrong key is a double-billing risk).
 *
 * Locks expire after the job's `uniqueFor()` seconds (port of lock_ttl).
 */
class UniqueJob
{
    public function handle($job, $next): void
    {
        $key = $this->lockKey($job);

        $lock = Cache::lock($key, $job->uniqueFor ?? 0);

        if (! $lock->get()) {
            // on_conflict: :log
            report("Unique job skipped, lock already held: {$key}");

            return;
        }

        try {
            $next($job);
        } finally {
            optional($lock)->release();
        }
    }

    private function lockKey($job): string
    {
        $reflection = new ReflectionClass($job);
        $base = $reflection->getShortName();

        if (method_exists($job, 'uniqueKey')) {
            return 'unique:job:'.$base.':'.$job->uniqueKey();
        }

        return 'unique:job:'.$base.':'.md5(serialize($job));
    }
}
