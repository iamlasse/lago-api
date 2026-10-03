<?php

declare(strict_types=1);

use App\Support\FrozenSql;

test('splits simple statements', function (): void {
    $statements = FrozenSql::statements("CREATE TABLE a (id int);\nCREATE TABLE b (id int);");

    expect($statements)->toBe([
        'CREATE TABLE a (id int)',
        'CREATE TABLE b (id int)',
    ]);
});

test('keeps semicolons inside single-quoted strings', function (): void {
    $statements = FrozenSql::statements("INSERT INTO t VALUES ('a;b');SELECT 1;");

    expect($statements)->toHaveCount(2)
        ->and($statements[0])->toBe("INSERT INTO t VALUES ('a;b')");
});

test('handles escaped single quotes', function (): void {
    $statements = FrozenSql::statements("INSERT INTO t VALUES ('it''s; fine');SELECT 1;");

    expect($statements)->toHaveCount(2);
});

test('keeps semicolons inside dollar-quoted function bodies', function (): void {
    $sql = <<<'SQL'
CREATE FUNCTION f() RETURNS trigger AS $func$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$func$ LANGUAGE plpgsql;
SELECT 1;
SQL;

    $statements = FrozenSql::statements($sql);

    expect($statements)->toHaveCount(2)
        ->and($statements[0])->toContain('NEW.updated_at := now();')
        ->and($statements[0])->toEndWith('LANGUAGE plpgsql');
});

test('ignores semicolons in line comments', function (): void {
    $statements = FrozenSql::statements("-- comment; with semicolon\nSELECT 1;");

    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toContain('-- comment; with semicolon');
});

test('skips empty statements', function (): void {
    expect(FrozenSql::statements(";;\n\n  ;\nSELECT 1;;"))->toBe(['SELECT 1']);
});

test('parses the real frozen structure.sql without losing statements', function (): void {
    $path = database_path('frozen/structure.sql');

    if (! is_file($path)) {
        $this->markTestSkipped('frozen/structure.sql not generated yet');
    }

    $statements = FrozenSql::statements((string) file_get_contents($path));

    $createTables = collect($statements)
        ->filter(fn ($s) => preg_match('/^CREATE TABLE /m', $s))->count();
    $createTypes = collect($statements)
        ->filter(fn ($s) => preg_match('/^CREATE TYPE /m', $s))->count();

    // Frozen file: 144 tables, 45 enum types (M2: the partman schema +
    // pg_partman extension + the partitioned enriched_events table and its
    // default partition are re-enabled verbatim — 141 + 3 tables).
    expect($createTables)->toBe(144)
        ->and($createTypes)->toBe(45)
        // Every statement must end balanced-ish: no truncated dollar quotes.
        ->and(collect($statements)->every(fn ($s) => mb_substr_count($s, '$$') % 2 === 0))->toBeTrue();
});
