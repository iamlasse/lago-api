<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Rails' db/seeds/60_email_activity_logs.rb inserts ClickHouse activity
 * rows (email.sent) via the clickhouse_activity_log factory.
 *
 * The Laravel port has no ClickHouse integration and no local
 * activity-log table — nothing to seed. Kept as a stub so DatabaseSeeder's
 * call list stays 1:1 with db/seeds.
 */
class EmailActivityLogsSeeder extends Seeder
{
    public function run(): void
    {
        // TODO(port): ClickHouse email activity logs.
    }
}
