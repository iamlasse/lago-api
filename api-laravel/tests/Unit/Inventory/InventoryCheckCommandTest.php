<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use Tests\TestCase;
use FilesystemIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

use function inv_artifact;
use function inv_gen_jobs;
use function inv_gen_tables;
use function inv_gen_graphql;
use function inv_seed_ledger;
use function inv_gen_services;
use function inv_read_artifact;
use function inv_write_artifact;
use function inv_gen_serializers;
use function inv_gen_routes_from_source;

/**
 * inventory:check mechanics against a fixture inventory directory built from
 * the rails-mini fixture — never the real tests/inventory, never the database.
 */
class InventoryCheckCommandTest extends TestCase
{
    private string $fixtureDir = '';

    private string $railsMini = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadGeneratorLibrary();

        $this->railsMini = base_path('tests/Unit/Inventory/fixtures/rails-mini');
        $this->fixtureDir = sys_get_temp_dir().'/inventory-check-'.uniqid('', true);
        mkdir($this->fixtureDir, 0777, true);

        $this->buildFixtureInventory();
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->fixtureDir);

        parent::tearDown();
    }

    public function test_fresh_artifacts_and_complete_ledger_pass(): void
    {
        $this->artisan('inventory:check', $this->commandOptions())
            ->assertExitCode(0);
    }

    public function test_drifted_artifact_fails_with_actionable_output(): void
    {
        file_put_contents($this->fixtureDir.'/tables.json', '{"tampered": true}'.PHP_EOL);

        $this->artisan('inventory:check', $this->commandOptions())
            ->assertExitCode(1)
            ->expectsOutputToContain('tables.json');
    }

    public function test_missing_artifact_fails(): void
    {
        unlink($this->fixtureDir.'/graphql.json');

        $this->artisan('inventory:check', $this->commandOptions())
            ->assertExitCode(1)
            ->expectsOutputToContain('graphql.json');
    }

    public function test_milestone_gate_fails_on_todo_rows(): void
    {
        file_put_contents($this->fixtureDir.'/scope_test.txt', "# fixture scope\nsvc:Invoices.CalculateFeesService\n");

        $this->artisan('inventory:check', $this->commandOptions(['--milestone' => 'test']))
            ->assertExitCode(1)
            ->expectsOutputToContain('svc:Invoices.CalculateFeesService');
    }

    public function test_milestone_gate_passes_when_row_meets_the_gate(): void
    {
        $this->patchLedgerRow('svc:Support.FrozenSql', [
            'laravel' => 'App\\Support\\FrozenSql',
            'status' => ['code' => 'done', 'test' => 'written', 'contract' => 'pass'],
            'tests' => ['tests/Unit/FrozenSqlTest.php'],
        ]);

        file_put_contents($this->fixtureDir.'/scope_test.txt', "svc:Support.FrozenSql\n");

        $this->artisan('inventory:check', $this->commandOptions(['--milestone' => 'test']))
            ->assertExitCode(0);
    }

    public function test_milestone_gate_flags_missing_class_even_when_status_claimed_done(): void
    {
        // Ledger claims done but the Laravel class does not exist.
        $this->patchLedgerRow('svc:Subscriptions.OrganizationBillingService', [
            'status' => ['code' => 'done', 'test' => 'written', 'contract' => 'pass'],
        ]);

        file_put_contents($this->fixtureDir.'/scope_test.txt', "svc:Subscriptions.OrganizationBillingService\n");

        $this->artisan('inventory:check', $this->commandOptions(['--milestone' => 'test']))
            ->assertExitCode(1)
            ->expectsOutputToContain('does not exist');
    }

    public function test_milestone_gate_flags_declared_test_file_that_does_not_exist(): void
    {
        $this->patchLedgerRow('svc:Support.FrozenSql', [
            'laravel' => 'App\\Support\\FrozenSql',
            'status' => ['code' => 'done', 'test' => 'written', 'contract' => 'pass'],
            'tests' => ['tests/Unit/Inventory/fixtures/does-not-exist.php'],
        ]);

        file_put_contents($this->fixtureDir.'/scope_test.txt', "svc:Support.FrozenSql\n");

        $this->artisan('inventory:check', $this->commandOptions(['--milestone' => 'test']))
            ->assertExitCode(1)
            ->expectsOutputToContain('does-not-exist.php');
    }

    public function test_missing_scope_file_fails(): void
    {
        $this->artisan('inventory:check', $this->commandOptions(['--milestone' => 'nonexistent']))
            ->assertExitCode(1)
            ->expectsOutputToContain('scope_nonexistent.txt');
    }

    /**
     * The generators are plain-PHP libraries under scripts/ (no composer
     * autoloading — deliberately, since no dependency changes are allowed).
     */
    private function loadGeneratorLibrary(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        foreach (['util', 'graphql', 'serializers', 'jobs', 'services', 'tables', 'routes', 'ledger'] as $library) {
            require_once base_path("scripts/inventory/lib/{$library}.php");
        }

        $loaded = true;
    }

    /**
     * Regenerates the artifact set from the mini fixture into the fixture
     * directory (the same bytes inventory:check will regenerate) and seeds
     * the ledger join.
     */
    private function buildFixtureInventory(): void
    {
        /** @var array<string, array{generator: string, function: string, rows: list<array<string, mixed>>, source: string, provisional: bool}> $generators */
        $generators = [
            'graphql.json' => ['generator' => 'gen_graphql', 'rows' => inv_gen_graphql($this->railsMini), 'source' => 'schema.json (Rails introspection result)', 'provisional' => false],
            'serializers.json' => ['generator' => 'gen_serializers', 'rows' => inv_gen_serializers($this->railsMini), 'source' => 'app/serializers/**/*.rb', 'provisional' => false],
            'jobs.json' => ['generator' => 'gen_jobs', 'rows' => inv_gen_jobs($this->railsMini), 'source' => 'app/jobs/**/*.rb', 'provisional' => false],
            'services.json' => ['generator' => 'gen_services', 'rows' => inv_gen_services($this->railsMini), 'source' => 'app/services/**/*.rb', 'provisional' => false],
            'tables.json' => ['generator' => 'gen_tables', 'rows' => inv_gen_tables($this->railsMini), 'source' => 'db/structure.sql CREATE TABLE blocks', 'provisional' => false],
            'rest.json' => ['generator' => 'gen_routes_from_source', 'rows' => inv_gen_routes_from_source($this->railsMini), 'source' => 'config/routes.rb + config/routes/{shared_api,plan_nested_api}.rb (STATIC parse — not authoritative)', 'provisional' => true],
        ];

        foreach ($generators as $name => $generator) {
            inv_write_artifact($name, inv_artifact(
                $generator['generator'],
                $generator['source'],
                $generator['rows'],
                $generator['provisional']
            ), $this->fixtureDir);
        }

        inv_seed_ledger($generators, $this->railsMini, $this->fixtureDir);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function patchLedgerRow(string $id, array $overrides): void
    {
        $ledger = inv_read_artifact('ledger.json', $this->fixtureDir);

        foreach (($ledger['rows'] ?? []) as $index => $row) {
            if ($row['id'] !== $id) {
                continue;
            }

            $ledger['rows'][$index] = array_merge($row, $overrides);
        }

        file_put_contents($this->fixtureDir.'/ledger.json', inv_canonical_json($ledger));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function commandOptions(array $extra = []): array
    {
        return array_merge([
            '--dir' => $this->fixtureDir,
            '--rails-path' => $this->railsMini,
        ], $extra);
    }

    private function removeRecursive(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
