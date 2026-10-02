<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use RuntimeException;
use Illuminate\Console\Command;

use function inv_artifact;
use function inv_rails_path;
use function inv_read_artifact;
use function inv_canonical_json;
use function inv_write_artifact;

/**
 * The mechanical gate of the coverage ledger.
 *
 * 1. Regenerates every inventory from the Rails checkout and fails on any
 *    drift against the committed artifacts (unless --update).
 * 2. Verifies the ledger covers every inventory row.
 * 3. With --milestone=mN, verifies every row in scope_mN.txt meets the gate:
 *    code=done, test ∈ {ported, written}, contract=pass, the Laravel target
 *    class exists (svc/ser/job rows) and every declared test file exists.
 *
 * Exit 0 only when everything holds — this is what CI runs.
 */
#[\Illuminate\Console\Attributes\Description('Verify inventory artifacts are fresh, the ledger is complete, and milestone scopes meet the gate')]
#[\Illuminate\Console\Attributes\Signature('inventory:check
                            {--update : Rewrite drifted artifacts instead of failing}
                            {--milestone= : Gate a milestone scope file (e.g. m1 -> scope_m1.txt)}
                            {--dir= : Override the committed inventory directory}
                            {--rails-path= : Override the Rails checkout path}')]
class InventoryCheck extends Command
{
    private const ARTIFACTS = [
        'graphql.json' => ['generator' => 'gen_graphql', 'function' => 'inv_gen_graphql', 'source' => 'schema.json (Rails introspection result)', 'provisional' => false],
        'serializers.json' => ['generator' => 'gen_serializers', 'function' => 'inv_gen_serializers', 'source' => 'app/serializers/**/*.rb', 'provisional' => false],
        'jobs.json' => ['generator' => 'gen_jobs', 'function' => 'inv_gen_jobs', 'source' => 'app/jobs/**/*.rb', 'provisional' => false],
        'services.json' => ['generator' => 'gen_services', 'function' => 'inv_gen_services', 'source' => 'app/services/**/*.rb', 'provisional' => false],
        'tables.json' => ['generator' => 'gen_tables', 'function' => 'inv_gen_tables', 'source' => 'db/structure.sql CREATE TABLE blocks', 'provisional' => false],
        'rest.json' => ['generator' => 'gen_routes_from_source', 'function' => 'inv_gen_routes_from_source', 'source' => 'config/routes.rb + config/routes/{shared_api,plan_nested_api}.rb (STATIC parse — not authoritative)', 'provisional' => true],
    ];

    private const MAX_REPORTED = 25;

    /** @var array<string, list<string>> */
    private array $failures = [];

    public function handle(): int
    {
        $this->loadGeneratorLibrary();

        $inventoryDir = $this->option('dir') ?: config('inventory.output_path');
        $railsPath = inv_rails_path($this->option('rails-path') ?: null);

        try {
            $regenerated = $this->regenerate($railsPath);
        } catch (RuntimeException|Throwable $exception) {
            $this->error('inventory: '.$exception->getMessage());
            $this->line('Fix: set LAGO_RAILS_PATH / --rails-path to a checkout of getlago/lago-api.');

            return self::FAILURE;
        }

        $this->checkDrift($regenerated, (string) $inventoryDir);
        $ledger = $this->checkLedgerCoverage($regenerated, (string) $inventoryDir);

        if ($this->option('milestone') !== null) {
            $this->checkMilestone((string) $this->option('milestone'), (string) $inventoryDir, $ledger);
        }

        return $this->report();
    }

    private function loadGeneratorLibrary(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        foreach (['util', 'graphql', 'serializers', 'jobs', 'services', 'tables', 'routes'] as $library) {
            require_once base_path("scripts/inventory/lib/{$library}.php");
        }

        $loaded = true;
    }

    /**
     * Regenerates every artifact's bytes in memory.
     *
     * @return array<string, string> artifact name => canonical JSON
     */
    private function regenerate(string $railsPath): array
    {
        $regenerated = [];

        foreach (self::ARTIFACTS as $name => $meta) {
            $rows = $meta['function']($railsPath);

            $regenerated[$name] = inv_canonical_json(inv_artifact(
                $meta['generator'],
                $meta['source'],
                $rows,
                $meta['provisional']
            ));
        }

        return $regenerated;
    }

    /**
     * @param  array<string, string>  $regenerated
     */
    private function checkDrift(array $regenerated, string $inventoryDir): void
    {
        if ($this->option('update')) {
            foreach ($regenerated as $name => $json) {
                inv_write_artifact($name, json_decode($json, true), $inventoryDir);
            }

            $this->info('Rewrote '.count($regenerated).' inventory artifacts in '.$inventoryDir);

            return;
        }

        foreach ($regenerated as $name => $json) {
            $committed = is_file($inventoryDir.'/'.$name) ? (string) file_get_contents($inventoryDir.'/'.$name) : null;

            if ($committed === null) {
                $this->recordFailure('drift', [$name, 'artifact missing from '.$inventoryDir, 'run: php scripts/inventory/gen-all.php']);

                continue;
            }

            if ($committed !== $json) {
                $this->recordFailure('drift', [$name, 'committed artifact drifted from a fresh regeneration', 're-run php scripts/inventory/gen-all.php after the Rails source changed, or inventory:check --update']);
            }
        }
    }

    /**
     * Verifies every inventory row has a ledger entry; returns the ledger rows
     * keyed by id for the milestone gate.
     *
     * @param  array<string, string>  $regenerated
     * @return array<string, array<string, mixed>>
     */
    private function checkLedgerCoverage(array $regenerated, string $inventoryDir): array
    {
        $ledger = inv_read_artifact('ledger.json', $inventoryDir) ?? [];

        if (! isset($ledger['rows'])) {
            $this->recordFailure('ledger', ['ledger.json', 'missing or malformed (no "rows")', 'run: php scripts/inventory/gen-all.php']);

            return [];
        }

        $byId = [];

        foreach ($ledger['rows'] as $row) {
            $byId[$row['id']] = $row;
        }

        $missing = [];

        foreach (self::ARTIFACTS as $name => $_) {
            $artifact = json_decode($regenerated[$name], true);

            foreach ($artifact['rows'] as $row) {
                if (! isset($byId[$row['id']])) {
                    $missing[] = $row['id'];
                }
            }
        }

        sort($missing);

        foreach (array_slice($missing, 0, self::MAX_REPORTED) as $id) {
            $this->recordFailure('ledger', [$id, 'no ledger entry', 're-run php scripts/inventory/gen-all.php to re-seed']);
        }

        if (count($missing) > self::MAX_REPORTED) {
            $this->line(sprintf('   … and %d more rows missing from the ledger.', count($missing) - self::MAX_REPORTED));
        }

        return $byId;
    }

    /**
     * @param  array<string, array<string, mixed>>  $ledger
     */
    private function checkMilestone(string $milestone, string $inventoryDir, array $ledger): void
    {
        $scopePath = $inventoryDir.'/scope_'.$milestone.'.txt';

        if (! is_file($scopePath)) {
            $this->recordFailure('scope', ['scope_'.$milestone.'.txt', 'scope file missing from '.$inventoryDir, 'create it (one row id per line, # comments allowed)']);

            return;
        }

        $scopeIds = [];

        foreach (file($scopePath) as $line) {
            $id = mb_trim($line);

            if ($id === '' || str_starts_with($id, '#')) {
                continue;
            }

            $scopeIds[] = $id;
        }

        if ($scopeIds === []) {
            $this->recordFailure('scope', ['scope_'.$milestone.'.txt', 'scope file contains no row ids', 'add the milestone\'s row ids']);

            return;
        }

        foreach ($scopeIds as $id) {
            $this->gateRow($id, $ledger[$id] ?? null);
        }
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function gateRow(string $id, ?array $row): void
    {
        if ($row === null) {
            $this->recordFailure('scope', [$id, 'not in ledger.json', 're-run php scripts/inventory/gen-all.php']);

            return;
        }

        $kind = strtok($id, ':') ?: '';
        $laravel = $row['laravel'] ?? null;

        // svc/ser/job rows must resolve to a real class; rest/gql targets are
        // projections and are gated on status alone.
        if (in_array($kind, ['svc', 'ser', 'job'], true) && is_string($laravel) && $laravel !== '') {
            if (! class_exists($laravel)) {
                $this->recordFailure('missing_class', [$id, "Laravel class [{$laravel}] does not exist", 'port the class (and its tests) or fix the ledger laravel target']);
            }
        }

        foreach ((array) ($row['tests'] ?? []) as $testPath) {
            if (! is_string($testPath) || $testPath === '') {
                continue;
            }

            if (! is_file(base_path($testPath))) {
                $this->recordFailure('missing_test', [$id, "declared test file [{$testPath}] does not exist", 'create the test or fix the ledger entry']);
            }
        }

        $status = $row['status'] ?? [];

        $code = $status['code'] ?? null;
        $test = $status['test'] ?? null;
        $contract = $status['contract'] ?? null;

        if ($code !== 'done') {
            $this->recordFailure('status', [$id, "status.code is [{$code}], gate requires [done]", 'port the code + tests, then flip the ledger row']);
        }

        if (! in_array($test, ['ported', 'written'], true)) {
            $this->recordFailure('status', [$id, "status.test is [{$test}], gate requires [ported] or [written]", 'tests travel with the code — same PR']);
        }

        if ($contract !== 'pass') {
            $this->recordFailure('status', [$id, "status.contract is [{$contract}], gate requires [pass]", 'capture/replay the contract scenario for this row']);
        }
    }

    /**
     * @param  list<string>  $parts  [row id / artifact, problem, hint]
     */
    private function recordFailure(string $category, array $parts): void
    {
        $this->failures[$category] ??= [];
        $this->failures[$category][] = $parts;
    }

    private function report(): int
    {
        if ($this->failures === []) {
            $this->info('inventory:check passed'.($this->option('milestone') !== null ? ' — milestone ['.$this->option('milestone').'] gate green.' : '.'));

            return self::SUCCESS;
        }

        $this->error('inventory:check FAILED');

        $total = 0;

        foreach ($this->failures as $category => $entries) {
            $total += count($entries);

            $this->line('');
            $this->line(sprintf('  %s — %d problem(s):', $category, count($entries)));

            foreach (array_slice($entries, 0, self::MAX_REPORTED) as [$subject, $problem, $hint]) {
                $this->line(sprintf('    • %s', $subject));
                $this->line(sprintf('        %s', $problem));
                $this->line(sprintf('        fix: %s', $hint));
            }

            if (count($entries) > self::MAX_REPORTED) {
                $this->line(sprintf('    … and %d more.', count($entries) - self::MAX_REPORTED));
            }
        }

        $this->line('');
        $this->line(sprintf('  %d problem(s) total. Nothing is tracked from memory: fix the source (Rails checkout, artifacts, ledger statuses), never the check.', $total));

        return self::FAILURE;
    }
}
