<?php

declare(strict_types=1);

namespace App\Services\ClickHouse;

use RuntimeException;
use Illuminate\Support\Facades\Http;

/**
 * The ClickHouse HTTP-interface query runner (port of the
 * `clickhouse` connection from Rails' config/database.yml consumed
 * through the clickhouse-activerecord adapter — ::Clickhouse::BaseRecord
 * and Events::Stores::Utils::ClickhouseConnection).
 *
 * No composer adapter: queries POST to the HTTP endpoint (default :8123)
 * with the statement as the body and `FORMAT JSON` appended, which the
 * HTTP interface answers with {"data": [...], ...} — every column as a
 * string, the same stringly-typed rows the Rails adapter surfaces (the
 * Rails stores call .to_i / BigDecimal() on the values for exactly that
 * reason).
 *
 * Port of ClickhouseConnection's retry semantics: a query ClickHouse
 * refuses to parse for hitting `max_query_size` / `max_ast_elements`
 * (a charge with thousands of filters serializes into a predicate past
 * both defaults) is replayed once with the limits raised as REQUEST
 * PARAMETERS (they must travel as params, not in a SETTINGS clause —
 * the parser needs the buffer size before it starts reading);
 * everything else retries up to three times. Memory-limit errors are
 * surfaced as MemoryLimitException (Rails:
 * Events::Stores::Clickhouse::MemoryLimitError).
 */
class Client
{
    /** ClickhouseConnection::MAX_RETRIES. */
    public const MAX_RETRIES = 3;

    /** ClickhouseConnection::MEMORY_ERROR_CODE. */
    public const MEMORY_ERROR_CODE = 'MEMORY_LIMIT_EXCEEDED';

    /** ClickhouseConnection::PARSER_LIMIT_ERRORS. */
    public const PARSER_LIMIT_ERRORS = ['Max query size exceeded', 'TOO_BIG_AST'];

    /**
     * ClickhouseConnection::QUERY_SETTINGS — raised parser limits for the
     * single replay (request parameters, not a SETTINGS clause).
     */
    public const QUERY_SETTINGS = ['max_query_size' => 4194304, 'max_ast_elements' => 500000];

    /**
     * Runs a SELECT and returns the JSON rows (each an associative
     * array column => string value).
     *
     * @return list<array<string, mixed>>
     */
    public function selectRows(string $sql): array
    {
        return $this->query($sql);
    }

    /** Port of `select_one` — the first row, or null. */
    public function selectOne(string $sql): ?array
    {
        $rows = $this->query($sql);

        return $rows[0] ?? null;
    }

    /** Port of `select_value` — the first column of the first row. */
    public function selectValue(string $sql): mixed
    {
        $row = $this->selectOne($sql);

        if ($row === null || $row === []) {
            return null;
        }

        return array_values($row)[0];
    }

    /**
     * Executes a statement (INSERT/DDL return no rows) and ignores the
     * response body.
     */
    public function execute(string $sql): void
    {
        $this->post($this->stripFormat($sql), []);
    }

    /**
     * Runs a SELECT ... FORMAT JSON and returns the parsed `data` rows.
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $sql): array
    {
        $response = $this->post($this->stripFormat($sql).' FORMAT JSON');

        $decoded = json_decode((string) $response->body(), true);

        if (! is_array($decoded) || ! array_key_exists('data', $decoded)) {
            throw new RuntimeException('ClickHouse returned an unreadable FORMAT JSON payload');
        }

        /** @var list<array<string, mixed>> */
        return $decoded['data'];
    }

    // -- Retry loop (ClickhouseConnection.with_parser_limit_retry) ------------

    /**
     * @param  array<string, int|string>  $settings
     */
    private function post(string $sql, array $settings = []): \Illuminate\Http\Client\Response
    {
        $attempts = 0;
        $replayedWithSettings = false;

        while (true) {
            $attempts++;

            $response = Http::withBasicAuth((string) config('lago.clickhouse.username'), (string) config('lago.clickhouse.password'))
                ->send('POST', $this->endpoint($settings), [
                    // The statement travels as the raw request body — the
                    // ClickHouse HTTP interface's native query transport
                    // (the default `json` body format would wrap it).
                    'body' => $sql,
                ]);

            if ($response->successful()) {
                return $response;
            }

            $message = (string) $response->body();

            if (str_contains($message, self::MEMORY_ERROR_CODE)) {
                throw new MemoryLimitException($message);
            }

            if (! $replayedWithSettings && $this->isParserLimitError($message)) {
                $replayedWithSettings = true;
                $settings = self::QUERY_SETTINGS;

                continue;
            }

            if ($attempts < self::MAX_RETRIES) {
                \Illuminate\Support\Sleep::usleep(50000);

                continue;
            }

            throw new RuntimeException('ClickHouse query failed ('.$response->status().'): '.$message);
        }
    }

    /**
     * @param  array<string, int|string>  $settings
     */
    private function endpoint(array $settings = []): string
    {
        $scheme = config('lago.clickhouse.ssl') ? 'https' : 'http';

        $params = http_build_query([
            'database' => config('lago.clickhouse.database'),
            ...$settings,
        ]);

        return "{$scheme}://".config('lago.clickhouse.host').':'.config('lago.clickhouse.port').'/?'.$params;
    }

    private function isParserLimitError(string $message): bool
    {
        foreach (self::PARSER_LIMIT_ERRORS as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** A caller may already have appended FORMAT — strip before re-appending. */
    private function stripFormat(string $sql): string
    {
        return preg_replace('/\s*FORMAT\s+\w+\s*$/i', '', mb_rtrim($sql, " \t\n\r;")) ?? $sql;
    }
}
