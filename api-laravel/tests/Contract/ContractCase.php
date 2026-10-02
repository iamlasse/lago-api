<?php

declare(strict_types=1);

namespace Tests\Contract;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Base class for contract tests: replays requests captured from the real
 * Rails API against this Laravel app and diffs the responses.
 *
 * Layout per scenario (committed to the repo):
 *
 *   tests/Contract/goldens/<scenario>/
 *     ├── fixture.sql      pg_dump --data-only of the scratch Rails DB
 *     ├── manifest.json    { "captured_at": "...", "requests": [ {method, path, headers, body} ] }
 *     └── 1.json, 2.json … parsed response bodies, one per request
 *
 * See scripts/contract/capture.sh for the Rails-side pipeline that produces
 * these. Run with: vendor/bin/pest --group contract (needs a disposable DB —
 * use DB_DATABASE=lago_test_c, never lago/lago_test).
 */
abstract class ContractCase extends BaseTestCase
{
    /**
     * Scenario name = directory under tests/Contract/goldens/. Set per test class.
     */
    protected string $scenario = '';

    protected string $goldensPath = '';

    /** @var array<string, mixed> decoded manifest.json */
    protected array $manifest = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->scenario === '') {
            static::fail('ContractCase subclasses must set $scenario.');
        }

        $this->goldensPath = base_path('tests/Contract/goldens/'.$this->scenario);

        if (! is_file($this->goldensPath.'/manifest.json')) {
            static::markTestSkipped("No goldens captured yet for scenario [{$this->scenario}] — run scripts/contract/capture.sh {$this->scenario} against the Rails stack.");
        }

        $this->manifest = json_decode((string) file_get_contents($this->goldensPath.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->loadFixture();
        $this->rewindTime();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Loads the captured data-only dump into the test database. The dump must
     * be schema-compatible with the frozen schema (it IS the same schema —
     * both come from db/structure.sql).
     */
    protected function loadFixture(): void
    {
        $sql = (string) file_get_contents($this->goldensPath.'/fixture.sql');

        DB::unprepared($sql);
    }

    /**
     * Freezes Laravel's clock at the captured instant so relative billing
     * math sees the same "now" Rails saw.
     */
    protected function rewindTime(): void
    {
        $capturedAt = $this->manifest['captured_at'] ?? null;

        if (! is_string($capturedAt) || $capturedAt === '') {
            static::fail('manifest.json must carry the captured instant as "captured_at".');
        }

        CarbonImmutable::setTestNow(new CarbonImmutable($capturedAt, 'UTC'));
    }

    /**
     * Replays one captured request through the HTTP kernel.
     *
     * @param  array{method: string, path: string, headers?: array<string, string>, body?: mixed}  $request
     */
    protected function replay(array $request): \Illuminate\Testing\TestResponse
    {
        $symfonyRequest = SymfonyRequest::create(
            uri: 'http://localhost'.($request['path'] ?? '/'),
            method: mb_strtoupper($request['method'] ?? 'GET'),
            parameters: [],
            cookies: [],
            files: [],
            server: $this->serverVariables($request),
            content: isset($request['body']) ? json_encode($request['body']) : null,
        );

        $kernel = $this->app->make(HttpKernel::class);

        return new \Illuminate\Testing\TestResponse($kernel->handle(Request::createFromBase($symfonyRequest)));
    }

    /**
     * Asserts a response matches its golden: required headers present, JSON
     * deep-equal modulo the Normalizer's allowlist.
     *
     * @param  list<array{path: string, expected: mixed, actual: mixed}>  $diffs
     */
    protected function assertMatchesGolden(\Illuminate\Testing\TestResponse $response, int $index, ?string $ledgerRowId = null): void
    {
        $goldenPath = $this->goldensPath.'/'.$index.'.json';

        static::assertFileExists($goldenPath, "Missing golden for request #{$index} — capture is incomplete.");

        // Required headers first: absence is a contract break the Normalizer
        // must not smooth over.
        foreach ($this->manifest['requests'][$index - 1]['required_headers'] ?? [] as $header => $expectedValue) {
            $response->assertHeader(mb_strtolower($header), $expectedValue);
        }

        $diffs = Normalizer::compareJson(
            (string) file_get_contents($goldenPath),
            (string) $response->getContent()
        );

        static::assertSame(
            [],
            $diffs,
            Normalizer::renderDiffs($diffs, $ledgerRowId)
        );
    }

    /**
     * Replays every request in the manifest, asserting each against its
     * numbered golden.
     *
     * @param  array<int, string>  $ledgerRowIds  request index (1-based) => ledger row id, surfaced on failure
     */
    protected function runScenario(array $ledgerRowIds = []): void
    {
        foreach ($this->manifest['requests'] ?? [] as $index => $request) {
            $response = $this->replay($request);
            $this->assertMatchesGolden($response, $index + 1, $ledgerRowIds[$index + 1] ?? null);
        }
    }

    /**
     * @param  array{method: string, path: string, headers?: array<string, string>}  $request
     * @return array<string, string>
     */
    private function serverVariables(array $request): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        foreach ($request['headers'] ?? [] as $name => $value) {
            $server['HTTP_'.mb_strtoupper(str_replace('-', '_', (string) $name))] = (string) $value;
        }

        return $server;
    }
}
