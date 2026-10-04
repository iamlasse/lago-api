<?php

declare(strict_types=1);

namespace Tests\Contract;

use Firebase\JWT\JWT;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
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
        JWT::$timestamp = null;

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
     * math sees the same "now" Rails saw — and so firebase/php-jwt validates
     * `exp` against the frozen instant. (At capture time Rails' travel_to
     * stubbed Time.now inside the jwt gem too; against the wall clock every
     * captured token is expired.)
     */
    protected function rewindTime(): void
    {
        $capturedAt = $this->manifest['captured_at'] ?? null;

        if (! is_string($capturedAt) || $capturedAt === '') {
            static::fail('manifest.json must carry the captured instant as "captured_at".');
        }

        $frozen = new CarbonImmutable($capturedAt, 'UTC');

        CarbonImmutable::setTestNow($frozen);
        JWT::$timestamp = $frozen->getTimestamp();
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

        // Convert BEFORE the kernel handle: the container instances the raw
        // request into 'request', and a Symfony instance there breaks
        // Laravel 13's strict-typed UrlGenerator rebinding.
        $illuminateRequest = Request::createFromBase($symfonyRequest);

        $kernel = $this->app->make(HttpKernel::class);

        return new \Illuminate\Testing\TestResponse($kernel->handle($illuminateRequest));
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
     * Per-request value substitution hook. The manifest carries the values
     * Rails captured (ids/tokens minted at capture time); requests whose
     * inputs derive from EARLIER replayed responses must use this run's
     * freshly minted values instead. Scenarios override as needed.
     *
     * @param  array<string, mixed>  $request
     * @param  array<int, \Illuminate\Testing\TestResponse>  $responses
     * @return array<string, mixed>
     */
    protected function substituteRequestValues(array $request, int $oneBasedIndex, array $responses): array
    {
        return $request;
    }

    /**
     * Replays every request in the manifest, asserting each against its
     * numbered golden. All mismatches are collected so one run reports the
     * full contract diff instead of stopping at the first failing request.
     *
     * @param  array<int, string>  $ledgerRowIds  request index (1-based) => ledger row id, surfaced on failure
     */
    protected function runScenario(array $ledgerRowIds = []): void
    {
        $failures = [];
        $responses = [];
        $baseAt = new CarbonImmutable($this->manifest['captured_at'], 'UTC');

        foreach ($this->manifest['requests'] ?? [] as $index => $request) {
            $request = $this->substituteRequestValues($request, $index + 1, $responses);

            // Scenarios whose request-created rows would TIE on created_at
            // (and so fall to the minted-id ordering tie-break) freeze each
            // request at its OWN instant — the manifest carries it as `at`
            // (captured_at unless the scenario stepped the clock, e.g. one
            // second between two POSTs feeding the same index). Without a
            // per-request instant the replay clock stays at captured_at,
            // which is exactly what the older scenarios recorded.
            $this->freezeRequestInstant($request['at'] ?? null, $baseAt);

            $response = $responses[$index + 1] = $this->replay($request);

            try {
                $this->assertMatchesGolden($response, $index + 1, $ledgerRowIds[$index + 1] ?? null);
            } catch (AssertionFailedError $error) {
                $failures[] = sprintf(
                    "request #%d %s %s\n%s",
                    $index + 1,
                    mb_strtoupper($request['method'] ?? 'GET'),
                    $request['path'] ?? '/',
                    $error->getMessage()
                );
            }
        }

        // Restore the scenario-wide frozen instant for side-assertions.
        CarbonImmutable::setTestNow($baseAt);
        JWT::$timestamp = $baseAt->getTimestamp();

        if ($failures !== []) {
            static::fail(count($failures).' of '.count($this->manifest['requests'] ?? [])." replayed request(s) diverge from the goldens:\n\n"
                .implode("\n\n", $failures));
        }
    }

    /**
     * Replaces minted-id TOKENS with the ids THIS replay minted. The
     * scenarios write a token (e.g. "GRANTED_TRANSACTION_ID") into the
     * manifest wherever the captured request addressed a row MINTED by an
     * earlier captured request — the real value was sent to Rails during
     * the capture; the replay substitutes its own (see
     * substituteRequestValues overrides).
     *
     * @param  array<string, string>  $map  token => replay-minted id
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function replaceTokens(array $request, array $map): array
    {
        if ($map === []) {
            return $request;
        }

        $walk = function (mixed $value) use (&$walk, $map): mixed {
            if (is_string($value)) {
                return strtr($value, $map);
            }

            if (is_array($value)) {
                return array_map($walk, $value);
            }

            return $value;
        };

        if (isset($request['path'])) {
            $request['path'] = $walk($request['path']);
        }

        if (array_key_exists('body', $request)) {
            $request['body'] = $walk($request['body']);
        }

        return $request;
    }

    /**
     * Freezes the replay clock at one captured request's instant (the
     * manifest's `at`, or the scenario-wide captured_at) — see runScenario.
     */
    private function freezeRequestInstant(?string $at, CarbonImmutable $baseAt): void
    {
        $instant = ($at !== null && $at !== '') ? new CarbonImmutable($at, 'UTC') : $baseAt;

        CarbonImmutable::setTestNow($instant);
        JWT::$timestamp = $instant->getTimestamp();
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
