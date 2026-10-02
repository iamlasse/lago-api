<?php

// Generator: jobs inventory from app/jobs/**/*.rb.
// Row ids: job:<ClassName> (bare class name — Sidekiq worker names are unique
// across the app). Captures queue_as, plus unique:/retry_on: stanzas verbatim
// because their options (lock_ttl, retry intervals) are contract-relevant.

if (! function_exists('inv_gen_jobs')) {
    /**
     * @return array<int, array<string, mixed>> rows sorted by id
     */
    function inv_gen_jobs(string $railsPath): array
    {
        $files = inv_scan_ruby_files($railsPath, 'app/jobs');

        $rows = [];

        foreach ($files as $file) {
            $relative = ltrim(substr($file, strlen($railsPath.'/app/jobs/')), '/');
            $class = inv_ruby_class_from_path($relative);
            $fullClass = inv_ruby_namespace_from_path($relative);
            $contents = (string) file_get_contents($file);

            $rows[] = [
                'id' => 'job:'.$class,
                'name' => $fullClass,
                'source' => 'app/jobs/'.$relative,
                'queue' => inv_job_queue_as($contents),
                'unique' => inv_job_capture_lines($contents, '/^\s*unique\b/'),
                'retry_on' => inv_job_capture_lines($contents, '/^\s*(retry_on|sidekiq_options.*retry)\b/'),
            ];
        }

        return inv_sort_rows($rows);
    }
}

if (! function_exists('inv_job_queue_as')) {
    function inv_job_queue_as(string $contents): string
    {
        if (preg_match('/^\s*queue_as\s+([A-Za-z_][\w]*)\s*$/m', $contents, $m) === 1) {
            return $m[1];
        }

        // queue_as do ... end / queue_as { ... } resolve at enqueue time.
        if (preg_match('/^\s*queue_as\s*(do|\{)/m', $contents) === 1) {
            return '(dynamic)';
        }

        return '(none)';
    }
}

if (! function_exists('inv_job_capture_lines')) {
    /**
     * @return string[] matching lines, trimmed, in file order
     */
    function inv_job_capture_lines(string $contents, string $pattern): array
    {
        $lines = [];

        foreach (preg_split('/\r?\n/', $contents) as $line) {
            if (preg_match($pattern, $line) === 1) {
                $lines[] = trim($line);
            }
        }

        return $lines;
    }
}
