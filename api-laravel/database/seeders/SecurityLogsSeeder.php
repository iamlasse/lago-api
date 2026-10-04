<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Rails' db/seeds/07_security_logs.rb produces ~23 security-log events into
 * the Kafka/ClickHouse pipeline (Utils::SecurityLog.produce via Karafka).
 *
 * The Laravel port has no Kafka producer and no ClickHouse integration (no
 * local security_logs table exists) — nothing to seed. Kept as a stub so
 * DatabaseSeeder's call list stays 1:1 with db/seeds.
 */
class SecurityLogsSeeder extends Seeder
{
    public function run(): void
    {
        // TODO(port): security logs pipeline (ClickHouse/Kafka).
    }
}
