<?php

declare(strict_types=1);

// Shared helpers for the inventory generators.
//
// Every generator is a plain function (guarded with function_exists so the
// artisan command can require these files without the standalone wrappers),
// deterministic, and side-effect free until the caller writes an artifact.
// Artifacts are canonical JSON: rows sorted by id, stable key order,
// 2-space pretty printing, unescaped slashes, trailing newline.

if (! defined('LAGO_INVENTORY_DEFAULT_RAILS_PATH')) {
    define('LAGO_INVENTORY_DEFAULT_RAILS_PATH', '/tmp/lago-api-exploration');
}

if (! function_exists('inv_repo_root')) {
    /**
     * Absolute path of the Laravel repo (the directory that holds scripts/ and tests/).
     */
    function inv_repo_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (! function_exists('inv_rails_path')) {
    /**
     * Rails source path: explicit argument wins, then LAGO_RAILS_PATH,
     * then the documented default.
     */
    function inv_rails_path(?string $override = null): string
    {
        $path = $override ?? (getenv('LAGO_RAILS_PATH') ?: LAGO_INVENTORY_DEFAULT_RAILS_PATH);

        return mb_rtrim((string) $path, '/');
    }
}

if (! function_exists('inv_assert_rails_path')) {
    /**
     * @throws RuntimeException when the Rails checkout is missing/unreadable
     */
    function inv_assert_rails_path(string $railsPath, string $subPath = ''): void
    {
        $target = $railsPath.($subPath !== '' ? '/'.$subPath : '');

        if (! is_dir($target) && ! is_file($target)) {
            throw new RuntimeException(
                "Rails source not found at [{$target}]. Point LAGO_RAILS_PATH (or --rails-path) ".
                'at a checkout of getlago/lago-api.'
            );
        }
    }
}

if (! function_exists('inv_output_dir')) {
    /**
     * Directory the committed artifacts live in (override for tests).
     */
    function inv_output_dir(?string $override = null): string
    {
        static $forced = null;

        if ($override !== null) {
            $forced = mb_rtrim($override, '/');
        }

        return $forced ?? (inv_repo_root().'/tests/inventory');
    }
}

if (! function_exists('inv_canonical_json')) {
    function inv_canonical_json(mixed $data): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('Failed to encode inventory artifact: '.json_last_error_msg());
        }

        return $json."\n";
    }
}

if (! function_exists('inv_sort_rows')) {
    /**
     * In-place sort by row id so regenerations are byte-stable.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    function inv_sort_rows(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }
}

if (! function_exists('inv_artifact')) {
    /**
     * Wrap rows in the standard artifact envelope (no timestamps — drift
     * detection compares bytes).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    function inv_artifact(string $generator, string $source, array $rows, bool $provisional = false): array
    {
        $artifact = [
            'generator' => $generator,
            'source' => $source,
            'row_count' => count($rows),
        ];

        if ($provisional) {
            $artifact['provisional'] = true;
            $artifact['warning'] = 'PROVISIONAL INVENTORY: rows were parsed statically from routes.rb, '
                .'which the coverage plan explicitly forbids trusting. Every row is marked '
                .'provisional:true. Replace this artifact by running gen_routes.rb inside Rails '
                .'(see scripts/inventory/README.md) before gating any milestone on rest rows.';
        }

        $artifact['rows'] = $rows;

        return $artifact;
    }
}

if (! function_exists('inv_write_artifact')) {
    /**
     * @param  array<string, mixed>  $artifact
     */
    function inv_write_artifact(string $name, array $artifact, ?string $dir = null): string
    {
        $dir ??= inv_output_dir();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir.'/'.$name;
        file_put_contents($path, inv_canonical_json($artifact));

        return $path;
    }
}

if (! function_exists('inv_read_artifact')) {
    /**
     * @return array<string, mixed>|null null when the artifact is not committed yet
     */
    function inv_read_artifact(string $name, ?string $dir = null): ?array
    {
        $path = ($dir ?? inv_output_dir()).'/'.$name;

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Inventory artifact [{$path}] is not valid JSON.");
        }

        return $decoded;
    }
}

if (! function_exists('inv_scan_ruby_files')) {
    /**
     * All *.rb files under a Rails subdirectory, sorted for determinism.
     *
     * @return string[] absolute paths
     */
    function inv_scan_ruby_files(string $railsPath, string $subDir): array
    {
        inv_assert_rails_path($railsPath, $subDir);

        $root = $railsPath.'/'.$subDir;
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'rb') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}

if (! function_exists('inv_ruby_class_from_path')) {
    /**
     * Camelizes a Rails file path (minus extension) into its dotted namespace
     * form: invoices/calculate_fees_service.rb => Invoices.CalculateFeesService.
     */
    function inv_ruby_class_from_path(string $relativePath): string
    {
        $withoutExt = preg_replace('/\.rb$/', '', $relativePath);

        $parts = explode('/', (string) $withoutExt);

        $parts = array_map(static fn(string $part): string => str_replace(' ', '', ucwords(str_replace('_', ' ', $part))), $parts);

        return implode('.', $parts);
    }
}

if (! function_exists('inv_ruby_namespace_from_path')) {
    /**
     * Double-colon form of the above: invoices/calculate_fees_service.rb
     * => Invoices::CalculateFeesService.
     */
    function inv_ruby_namespace_from_path(string $relativePath): string
    {
        return str_replace('.', '::', inv_ruby_class_from_path($relativePath));
    }
}

if (! function_exists('inv_truncate')) {
    function inv_truncate(string $value, int $max = 120): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
