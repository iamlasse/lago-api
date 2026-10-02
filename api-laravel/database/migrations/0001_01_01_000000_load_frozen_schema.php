<?php

declare(strict_types=1);

use App\Support\FrozenSql;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Loads the frozen Lago schema, taken verbatim from the Rails app's
 * db/structure.sql (the schema source of truth — Rails uses
 * `schema_format = :sql`, there is no schema.rb).
 *
 * The file is preprocessed at commit time by scripts/freeze-schema.php; the
 * only removals are documented in database/frozen/EXCLUSIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = file_get_contents(__DIR__.'/../frozen/structure.sql');

        foreach (FrozenSql::statements($sql) as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The frozen Lago schema is irreversible — do not roll back.');
    }
};
