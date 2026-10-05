<?php

declare(strict_types=1);

/**
 * Generates docs/PORT_STATUS.md from the committed coverage ledger.
 *
 * Reads JSON files only (tests/inventory/ledger.json, scope_m1.txt,
 * rest.json) — no database, no artisan, no network. Safe to run alongside
 * the test suite. Output is deterministic: no timestamps, so re-running on
 * an unchanged ledger produces byte-identical markdown.
 *
 * Usage:
 *   php scripts/port-status.php               # writes docs/PORT_STATUS.md
 *   php scripts/port-status.php --stdout      # print instead of write
 *   php scripts/port-status.php --output=path # override the output path
 */
$root = dirname(__DIR__);

$options = getopt('', ['stdout', 'output::']);
$stdout = array_key_exists('stdout', $options);
$output = $options['output'] ?? ($root.'/docs/PORT_STATUS.md');

$ledgerPath = $root.'/tests/inventory/ledger.json';
$scopePath = $root.'/tests/inventory/scope_m1.txt';
$restPath = $root.'/tests/inventory/rest.json';

$ledger = json_decode((string) file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
$rows = $ledger['rows'];

$restProvisional = false;
if (is_file($restPath)) {
    $rest = json_decode((string) file_get_contents($restPath), true, 512, JSON_THROW_ON_ERROR);
    $restProvisional = (bool) ($rest['provisional'] ?? false);
}

$kindOf = fn (string $id): string => explode(':', $id)[0];

$domainOf = function (string $id): string {
    $parts = explode(':', $id, 2);
    $kind = $parts[0];
    $rest = $parts[1] ?? '';

    // REST, GraphQL and table rows group by kind; namespaced rows (svc/job/ser)
    // group by their first namespace segment (Invoices, Webhooks, Clickhouse…).
    if (in_array($kind, ['rest', 'gql', 'table'], true)) {
        return $kind;
    }

    return str_contains($rest, '.') ? strtok($rest, '.') : $rest;
};

// --- headline ---------------------------------------------------------------

$total = count($rows);
$byCode = ['todo' => 0, 'in_progress' => 0, 'done' => 0];
$byTest = ['todo' => 0, 'ported' => 0, 'written' => 0];
$contractPass = 0;
foreach ($rows as $row) {
    $byCode[$row['status']['code']] = ($byCode[$row['status']['code']] ?? 0) + 1;
    $byTest[$row['status']['test']] = ($byTest[$row['status']['test']] ?? 0) + 1;
    if ($row['status']['contract'] === 'pass') {
        $contractPass++;
    }
}

// --- progress by kind -------------------------------------------------------

$kinds = ['rest', 'gql', 'svc', 'ser', 'job', 'table'];
$byKind = [];
foreach ($kinds as $kind) {
    $byKind[$kind] = ['rows' => 0, 'done' => 0, 'in_progress' => 0, 'pass' => 0];
}
foreach ($rows as $row) {
    $kind = $kindOf($row['id']);
    if (! isset($byKind[$kind])) {
        $byKind[$kind] = ['rows' => 0, 'done' => 0, 'in_progress' => 0, 'pass' => 0];
    }
    $byKind[$kind]['rows']++;
    if ($row['status']['code'] === 'done') {
        $byKind[$kind]['done']++;
    }
    if ($row['status']['code'] === 'in_progress') {
        $byKind[$kind]['in_progress']++;
    }
    if ($row['status']['contract'] === 'pass') {
        $byKind[$kind]['pass']++;
    }
}

// --- M1 scope gate ----------------------------------------------------------

$scopeIds = [];
foreach (file($scopePath) ?: [] as $line) {
    $line = mb_trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $scopeIds[] = $line;
}
$byId = [];
foreach ($rows as $row) {
    $byId[$row['id']] = $row;
}
$m1Done = 0;
$m1Pass = 0;
$m1Missing = [];
$m1Open = [];
foreach ($scopeIds as $id) {
    $row = $byId[$id] ?? null;
    if ($row === null) {
        $m1Missing[] = $id;

        continue;
    }
    if ($row['status']['code'] === 'done') {
        $m1Done++;
    } else {
        $m1Open[] = $id;
    }
    if ($row['status']['contract'] === 'pass') {
        $m1Pass++;
    } else {
        $m1Open[] = $id.' (code='.$row['status']['code'].', contract='.$row['status']['contract'].')';
    }
}
$m1GateGreen = $m1Done === count($scopeIds) && $m1Pass === count($scopeIds) && $m1Missing === [];

// --- remaining backlog ------------------------------------------------------

// Deferred: ClickHouse-direct store rows (the port meters against the frozen
// Postgres events store; the ClickHouse store classes stay unported).
$clickhouse = [];
foreach ($rows as $row) {
    if (mb_stripos($row['id'], 'clickhouse') !== false || mb_stripos($row['notes'], 'clickhouse') !== false) {
        $clickhouse[] = $row;
    }
}
$clickhouseDone = count(array_filter($clickhouse, fn ($row) => $row['status']['code'] === 'done'));

// Done rows whose contract is still untested — the golden scenario covering
// them has not been captured yet. Table rows are excluded: the frozen schema
// is verified by the catalog diff (CI), not by contract goldens.
$noScenario = [];
foreach ($rows as $row) {
    if ($row['status']['code'] === 'done' && $row['status']['contract'] === 'untested'
        && $kindOf($row['id']) !== 'table') {
        $noScenario[$kindOf($row['id'])] = ($noScenario[$kindOf($row['id'])] ?? 0) + 1;
    }
}
$noScenarioTotal = array_sum($noScenario);

// Open work grouped by domain.
$todoDomains = [];
foreach ($rows as $row) {
    if ($row['status']['code'] === 'done') {
        continue;
    }
    $domain = $domainOf($row['id']);
    $todoDomains[$domain] = ($todoDomains[$domain] ?? 0) + 1;
}
arsort($todoDomains);

$topDomains = 20;
$domainRows = [];
$shown = 0;
$shownRows = 0;
foreach ($todoDomains as $domain => $count) {
    if ($shown < $topDomains) {
        $domainRows[] = '| `'.$domain.'` | '.$count.' |';
        $shown++;
        $shownRows += $count;
    }
}
$remainingDomains = count($todoDomains) - $shown;
$remainingRows = array_sum($todoDomains) - $shownRows;

// --- render -----------------------------------------------------------------

$lines = [];
$lines[] = '# Port status';
$lines[] = '';
$lines[] = 'Generated by `php scripts/port-status.php` from the committed coverage ledger';
$lines[] = '(`tests/inventory/ledger.json`, joined inventories documented in';
$lines[] = '[`tests/inventory/FORMAT.md`](../tests/inventory/FORMAT.md)). Do not edit by hand —';
$lines[] = 're-run the script instead. Every `done` row carries its tests in the same PR,';
$lines[] = 'per the ledger rules (`code=done` requires `test ∈ {ported, written}`).';
$lines[] = '';
$lines[] = '## Headline';
$lines[] = '';
$lines[] = '| Metric | Count |';
$lines[] = '| --- | --- |';
$lines[] = '| Ledger rows (Rails surface under coverage) | '.$total.' |';
$lines[] = '| Done | '.$byCode['done'].' |';
$lines[] = '| In progress | '.$byCode['in_progress'].' |';
$lines[] = '| Todo | '.$byCode['todo'].' |';
$lines[] = '| Contract goldens replaying green (`contract=pass`) | '.$contractPass.' |';
$lines[] = '| Tests ported from Rails specs | '.$byTest['ported'].' |';
$lines[] = '| Tests written first (no Rails spec to port) | '.$byTest['written'].' |';
$lines[] = '';
$lines[] = '## Progress by kind';
$lines[] = '';
$lines[] = '| Kind | Rows | Done | In progress | Contract pass |';
$lines[] = '| --- | --- | --- | --- | --- |';
foreach ($kinds as $kind) {
    $k = $byKind[$kind];
    $note = $kind === 'rest' && $restProvisional ? ' (provisional inventory)' : '';
    $lines[] = '| `'.$kind.'`'.$note.' | '.$k['rows'].' | '.$k['done'].' | '.$k['in_progress'].' | '.$k['pass'].' |';
}
$lines[] = '';
$lines[] = '`table` rows are the frozen schema itself (all done by construction — the';
$lines[] = 'loader builds the database from Rails\' `db/structure.sql`).';
$lines[] = '';
$lines[] = '## Milestone 1 scope';
$lines[] = '';
$lines[] = '`tests/inventory/scope_m1.txt` gates '.count($scopeIds).' rows';
$lines[] = '(`php artisan inventory:check --milestone=m1`: every row must reach';
$lines[] = '`code=done`, `test ∈ {ported, written}`, `contract=pass`).';
$lines[] = '';
$lines[] = '- Done: '.$m1Done.' / '.count($scopeIds);
$lines[] = '- Contract pass: '.$m1Pass.' / '.count($scopeIds);
if ($m1GateGreen) {
    $lines[] = '- Gate: green — the whole M1 scope meets the gate.';
} else {
    $lines[] = '- Gate: red — '.count($m1Open).' scope rows still below the gate:';
    foreach (array_slice($m1Open, 0, 50) as $open) {
        $lines[] = '  - `'.$open.'`';
    }
    if (count($m1Open) > 50) {
        $lines[] = '  - …and '.(count($m1Open) - 50).' more';
    }
}
if ($m1Missing !== []) {
    $lines[] = '- Missing from the ledger: '.implode(', ', $m1Missing);
}
$lines[] = '';
$lines[] = '## Remaining backlog';
$lines[] = '';
$lines[] = '### Deferred — ClickHouse-direct store ('.count($clickhouse).' rows)';
$lines[] = '';
$lines[] = 'The port meters against the frozen Postgres `events` table (the';
$lines[] = '`Events::Stores::PostgresStore` port). The Rails ClickHouse store classes';
$lines[] = 'have no Laravel counterpart and are deliberately left `todo`:'.($clickhouseDone > 0 ? ' '.$clickhouseDone.' of them are marked done.' : '');
$lines[] = '';
foreach ($clickhouse as $row) {
    $lines[] = '- `'.$row['id'].'` ('.$row['status']['code'].')';
}
if ($clickhouse === []) {
    $lines[] = '- (none in the current ledger)';
}
$lines[] = '';
$lines[] = '### Done without a contract scenario ('.$noScenarioTotal.' rows)';
$lines[] = '';
$lines[] = 'Rows whose code and tests are in, but whose behavior no captured golden';
$lines[] = 'scenario covers yet (`contract=untested`). The contract harness';
$lines[] = '([`scripts/contract/README.md`](../scripts/contract/README.md)) closes these';
$lines[] = 'as new scenarios are captured:'.($noScenarioTotal > 0 ? '' : ' (none right now).');
$lines[] = '`table` rows are excluded — the frozen schema is verified by the CI catalog';
$lines[] = 'diff, not by contract goldens.';
$lines[] = '';
$lines[] = '| Kind | Done, contract untested |';
$lines[] = '| --- | --- |';
foreach ($kinds as $kind) {
    if (($noScenario[$kind] ?? 0) > 0) {
        $lines[] = '| `'.$kind.'` | '.$noScenario[$kind].' |';
    }
}
$lines[] = '';
$lines[] = '### Open domains';
$lines[] = '';
$lines[] = 'Every not-yet-done row grouped by domain (REST and GraphQL rows by kind,';
$lines[] = 'services/jobs/serializers by their first namespace segment).';
$lines[] = '';
$lines[] = '| Domain | Open rows |';
$lines[] = '| --- | --- |';
foreach ($domainRows as $row) {
    $lines[] = $row;
}
if ($remainingDomains > 0) {
    $lines[] = '| …'.$remainingDomains.' smaller domains | '.$remainingRows.' |';
}
$lines[] = '';
$lines[] = '## Test gates';
$lines[] = '';
$lines[] = '1. `vendor/bin/pint --dirty` — style.';
$lines[] = '2. `vendor/bin/phpstan` (larastan) — static analysis (report-only until findings clear).';
$lines[] = '3. `vendor/bin/pest` — the full suite.';
$lines[] = '4. `php artisan inventory:check` — inventory artifacts fresh, ledger complete,';
$lines[] = '   milestone scopes meet the gate (`--milestone=m1` for the scope above).';
$lines[] = '5. Contract suite — `PAO_DISABLE=1 DB_DATABASE=<disposable> ./vendor/bin/pest tests/Contract`';
$lines[] = '   replays the captured Rails goldens.';
$lines[] = '';

$markdown = implode("\n", $lines);

if ($stdout) {
    echo $markdown;

    exit(0);
}

if (! is_dir(dirname($output))) {
    mkdir(dirname($output), 0755, true);
}
file_put_contents($output, $markdown);
echo 'Wrote '.$output.' ('.mb_strlen($markdown)." bytes)\n";
