<?php

declare(strict_types=1);

// Ledger join: takes the freshly generated inventories plus a scan of the
// Laravel app/ tree and produces tests/inventory/ledger.json.
//
// Re-running is safe and idempotent in the useful direction: rows already
// present in the committed ledger keep their hand-maintained `laravel`,
// `status`, `tests` and `notes` (the port workflow edits those); only rows
// missing from the ledger get seeded, and rows whose inventory id vanished
// are dropped.

if (! function_exists('inv_seed_ledger')) {
    /**
     * @param  array<string, array{rows: list<array<string, mixed>>, source: string, provisional: bool}>  $generators
     */
    function inv_seed_ledger(array $generators, string $railsPath, ?string $dir = null): string
    {
        $dir ??= inv_output_dir();
        $appClasses = inv_ledger_scan_app_classes(inv_repo_root().'/app');
        $existing = (inv_read_artifact('ledger.json', $dir) ?? [])['rows'] ?? [];

        $existingById = [];

        foreach ($existing as $row) {
            $existingById[$row['id']] = $row;
        }

        $rows = [];

        foreach ($generators as $generator) {
            foreach ($generator['rows'] as $inventoryRow) {
                $id = (string) $inventoryRow['id'];

                $seeded = inv_ledger_seed_row($inventoryRow, $appClasses);

                if (isset($existingById[$id])) {
                    // Preserve the hand-maintained fields, refresh `source`.
                    $prior = $existingById[$id];
                    $prior['source'] = $seeded['source'];

                    if (($prior['laravel'] ?? null) === null) {
                        $prior['laravel'] = $seeded['laravel'];
                    }

                    $rows[] = $prior;

                    continue;
                }

                $rows[] = $seeded;
            }
        }

        $rows = inv_sort_rows($rows);

        return inv_write_artifact('ledger.json', inv_artifact(
            'scripts/inventory/lib/ledger.php (join via gen-all.php)',
            'join of all inventories with app/ scan; statuses hand-maintained after seeding',
            $rows,
            provisional: false
        ), $dir);
    }
}

if (! function_exists('inv_ledger_seed_row')) {
    /**
     * @param  array<string, mixed>  $inventoryRow
     * @param  array<string, bool>  $appClasses  FQCN => true
     * @return array<string, mixed>
     */
    function inv_ledger_seed_row(array $inventoryRow, array $appClasses): array
    {
        $id = (string) $inventoryRow['id'];
        $laravel = inv_ledger_laravel_target($inventoryRow, $appClasses);

        // Tables ship with the frozen schema (M0) and are exercised by the
        // frozen-sql test — the only rows that can start done/ported.
        if (str_starts_with($id, 'table:')) {
            return [
                'id' => $id,
                'source' => $inventoryRow['source'],
                'laravel' => $laravel,
                'status' => ['code' => 'done', 'test' => 'ported', 'contract' => 'untested'],
                'tests' => ['tests/Unit/FrozenSqlTest.php'],
                'notes' => 'Provided verbatim by the frozen schema loader (M0).',
            ];
        }

        $code = ($laravel !== null && ($appClasses[$laravel] ?? false)) ? 'in_progress' : 'todo';

        return [
            'id' => $id,
            'source' => $inventoryRow['source'],
            'laravel' => $laravel,
            'status' => ['code' => $code, 'test' => 'todo', 'contract' => 'untested'],
            'tests' => [],
            'notes' => $code === 'in_progress'
                ? 'Laravel class exists (M0 skeleton) — needs its tests before code:done.'
                : '',
        ];
    }
}

if (! function_exists('inv_ledger_laravel_target')) {
    /**
     * Maps a Rails inventory row to its Laravel target class per the plan's
     * namespace convention (Services/, Serializers/, Jobs/, Http/Controllers/Api/,
     * GraphQL/). Returns the FQCN (a projection — it may not exist yet), or
     * null when nothing meaningful can be named.
     *
     * @param  array<string, mixed>  $inventoryRow
     * @param  array<string, bool>  $appClasses
     */
    function inv_ledger_laravel_target(array $inventoryRow, array $appClasses): ?string
    {
        $id = (string) $inventoryRow['id'];

        return match (true) {
            str_starts_with($id, 'svc:') => 'App\\Services\\'.str_replace('::', '\\', (string) $inventoryRow['name']),
            str_starts_with($id, 'ser:') => inv_ledger_serializer_target((string) $inventoryRow['name']),
            str_starts_with($id, 'job:') => 'App\\Jobs\\'.str_replace('::', '\\', (string) $inventoryRow['name']),
            str_starts_with($id, 'rest:') => inv_ledger_rest_controller((string) ($inventoryRow['handler'] ?? '')),
            str_starts_with($id, 'gql:') => 'App\\GraphQL\\'.ucfirst((string) $inventoryRow['kind']).'\\'.ucfirst((string) $inventoryRow['name']),
            str_starts_with($id, 'table:') => inv_ledger_model_guess((string) $inventoryRow['name'], $appClasses),
            default => null,
        };
    }
}

if (! function_exists('inv_ledger_serializer_target')) {
    /**
     * Top-level Rails serializers live under the Base namespace in Laravel
     * (plan: serializers/{model,collection}_serializer.rb → Serializers/Base/*);
     * namespaced ones keep their namespace (V1::CustomerSerializer →
     * App\Serializers\V1\CustomerSerializer).
     */
    function inv_ledger_serializer_target(string $rubyName): string
    {
        $parts = explode('::', $rubyName);

        if (count($parts) === 1) {
            return 'App\\Serializers\\Base\\'.$parts[0];
        }

        return 'App\\Serializers\\'.implode('\\', $parts);
    }
}

if (! function_exists('inv_ledger_rest_controller')) {
    /**
     * "api/v1/customers/usage#current" (or "/api/v1/plans/charges" for
     * absolute handlers) → "App\Http\Controllers\Api\V1\Customers\UsageController".
     */
    function inv_ledger_rest_controller(string $handler): ?string
    {
        $controller = explode('#', $handler)[0];

        if ($controller === '') {
            return null;
        }

        $parts = explode('/', mb_trim($controller, '/'));
        $parts = array_map(static fn (string $part): string => ucfirst(str_replace('_', '', ucwords($part, '_'))), $parts);

        // "api" → "Api", "v1" → "V1", "customers" → "Customers".
        return 'App\\Http\\Controllers\\'.implode('\\', $parts).'Controller';
    }
}

if (! function_exists('inv_ledger_model_guess')) {
    /**
     * Naive table → model guess used only to annotate `laravel` on table rows
     * (null when no matching App\Models\* class exists).
     *
     * @param  array<string, bool>  $appClasses
     */
    function inv_ledger_model_guess(string $table, array $appClasses): ?string
    {
        $last = explode('_', $table);
        $word = end($last);

        $singular = match (true) {
            str_ends_with($word, 'ies') => mb_substr($word, 0, -3).'y',
            str_ends_with($word, 'ses') => mb_substr($word, 0, -2),
            str_ends_with($word, 's') && ! str_ends_with($word, 'ss') => mb_substr($word, 0, -1),
            default => $word,
        };

        $candidate = 'App\\Models\\'.ucfirst($singular);

        return ($appClasses[$candidate] ?? false) ? $candidate : null;
    }
}

if (! function_exists('inv_ledger_scan_app_classes')) {
    /**
     * FQCN set for every class declared under app/ (path → namespace mapping
     * per composer.json PSR-4 "App\": "app/").
     *
     * @return array<string, bool>
     */
    function inv_ledger_scan_app_classes(string $appPath): array
    {
        $classes = [];

        if (! is_dir($appPath)) {
            return $classes;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = mb_substr($file->getPathname(), mb_strlen($appPath) + 1, -4);

            $classes['App\\'.str_replace('/', '\\', $relative)] = true;
        }

        return $classes;
    }
}
