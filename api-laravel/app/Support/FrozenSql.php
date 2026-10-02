<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Splits raw multi-statement SQL (pg_dump output) into individual statements,
 * aware of dollar-quoted strings ($tag$ ... $tag$) and single-quoted strings,
 * so function bodies containing semicolons stay intact.
 */
class FrozenSql
{
    /**
     * @return string[] non-empty statements, in order
     */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = mb_strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            // Line comment: copy through end of line, semicolons inside don't count.
            if ($char === '-' && $sql[$i + 1] ?? '' === '-') {
                $end = mb_strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $current .= mb_substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            // Single-quoted string with '' escaping.
            if ($char === "'") {
                $j = $i + 1;
                while ($j < $length) {
                    if ($sql[$j] === "'") {
                        if (($sql[$j + 1] ?? '') === "'") {
                            $j += 2;

                            continue;
                        }
                        $j++;
                        break;
                    }
                    $j++;
                }
                $current .= mb_substr($sql, $i, $j - $i);
                $i = $j;

                continue;
            }

            // Dollar-quoted string ($tag$ ... $tag$), e.g. function bodies.
            if ($char === '$' && preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i) === 1) {
                $tag = $m[0];
                $end = mb_strpos($sql, $tag, $i + mb_strlen($tag));
                $end = $end === false ? $length : $end + mb_strlen($tag);
                $current .= mb_substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($char === ';') {
                if (mb_trim($current) !== '') {
                    $statements[] = mb_trim($current);
                }
                $current = '';
                $i++;

                continue;
            }

            $current .= $char;
            $i++;
        }

        if (mb_trim($current) !== '') {
            $statements[] = mb_trim($current);
        }

        return $statements;
    }
}
