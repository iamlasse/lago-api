<?php

// Generator: tables inventory from db/structure.sql (the schema source of
// truth). One row per CREATE TABLE block with a column count and a hash of
// the ordered column list, so any drift between the frozen schema and the
// Rails structure is mechanically detectable.

if (! function_exists('inv_gen_tables')) {
    /**
     * @return array<int, array<string, mixed>> rows sorted by id
     */
    function inv_gen_tables(string $railsPath): array
    {
        inv_assert_rails_path($railsPath, 'db/structure.sql');

        $sql = (string) file_get_contents($railsPath.'/db/structure.sql');

        $rows = [];

        foreach (inv_tables_split_create_blocks($sql) as $block) {
            $columns = inv_tables_parse_columns($block['body']);

            $rows[] = [
                'id' => 'table:'.$block['name'],
                'name' => $block['name'],
                'source' => 'db/structure.sql',
                'columns' => count($columns),
                'columns_hash' => md5(implode(',', $columns)),
                'partitioned' => $block['partitioned'],
            ];
        }

        return inv_sort_rows($rows);
    }
}

if (! function_exists('inv_tables_split_create_blocks')) {
    /**
     * Finds `CREATE TABLE <schema>.<name> (` ... `);` blocks, skipping
     * `CREATE TABLE ... PARTITION OF ...` (no column body of its own).
     *
     * @return array<int, array{name: string, body: string, partitioned: bool}>
     */
    function inv_tables_split_create_blocks(string $sql): array
    {
        $blocks = [];

        if (preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?(?:[A-Za-z_][\w]*\.)?"?([A-Za-z_]\w*)"?\s*\(/', $sql, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return $blocks;
        }

        foreach ($matches[1] as $index => [$name, $offset]) {
            // `CREATE TABLE x PARTITION OF parent (...)` blocks are matched too;
            // their declared column body is still what drift detection wants.
            $bodyStart = strpos($sql, '(', (int) $offset);

            if ($bodyStart === false) {
                continue;
            }

            $bodyEnd = inv_tables_find_block_close($sql, $bodyStart);

            if ($bodyEnd === null) {
                continue;
            }

            $body = substr($sql, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            $partitioned = str_contains(substr($sql, $bodyEnd, 120), 'PARTITION BY');

            $blocks[] = [
                'name' => $name,
                'body' => $body,
                'partitioned' => $partitioned,
            ];
        }

        return $blocks;
    }
}

if (! function_exists('inv_tables_find_block_close')) {
    /**
     * Position of the ")" that closes the block opening at $openOffset,
     * skipping parens inside quoted strings and dollar-quoted bodies.
     */
    function inv_tables_find_block_close(string $sql, int $openOffset): ?int
    {
        $length = strlen($sql);
        $depth = 0;
        $i = $openOffset;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === "'") {
                $i = inv_tables_skip_single_quote($sql, $i);

                continue;
            }

            if ($char === '$' && preg_match('/\$[A-Za-z_]*\$/', $sql, $m, 0, $i) === 1) {
                $tag = $m[0];
                $close = strpos($sql, $tag, $i + strlen($tag));
                $i = $close === false ? $length : $close + strlen($tag);

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }

            $i++;
        }

        return null;
    }
}

if (! function_exists('inv_tables_skip_single_quote')) {
    function inv_tables_skip_single_quote(string $sql, int $start): int
    {
        $i = $start + 1;
        $length = strlen($sql);

        while ($i < $length) {
            if ($sql[$i] === "'") {
                if (($sql[$i + 1] ?? '') === "'") {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return $length;
    }
}

if (! function_exists('inv_tables_parse_columns')) {
    /**
     * Top-level column names of a CREATE TABLE body, in declaration order.
     * pg_dump indents column definitions with exactly 4 spaces; table
     * constraints (CONSTRAINT/PRIMARY KEY/...) are excluded.
     *
     * @return string[]
     */
    function inv_tables_parse_columns(string $body): array
    {
        $columns = [];
        $depth = 0;

        foreach (preg_split('/\r?\n/', $body) as $line) {
            $depth += substr_count($line, '(') - substr_count($line, ')');

            if ($depth !== 0 || preg_match('/^    (".*?"|[A-Za-z_]\w*)\s/', $line) !== 1) {
                continue;
            }

            $name = $line[4] === '"' ? trim(substr($line, 4, (int) strpos(substr($line, 4), '"', 1)), '"') : strtok(substr($line, 4), " \t");

            if ($name === false || $name === '') {
                continue;
            }

            if (preg_match('/^(CONSTRAINT|PRIMARY|UNIQUE|FOREIGN|CHECK|EXCLUDE|LIKE)\b/i', $name) === 1) {
                continue;
            }

            $columns[] = $name;
        }

        return $columns;
    }
}
