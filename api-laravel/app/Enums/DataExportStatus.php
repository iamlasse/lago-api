<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * data_exports.status — integer column, Rails enum order is the stored value
 * (app/models/data_export.rb `enum :status, STATUSES`). Never renumber:
 * pending=0, processing=1, completed=2, failed=3.
 */
enum DataExportStatus: int
{
    case Pending = 0;
    case Processing = 1;
    case Completed = 2;
    case Failed = 3;

    /** @return list<string> Rails' DataExport::STATUSES names, in order. */
    public static function options(): array
    {
        return ['pending', 'processing', 'completed', 'failed'];
    }

    /** Rails assigns the enum NAME; the column stores the integer position. */
    public function label(): string
    {
        return self::options()[$this->value];
    }

    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        $index = array_search(mb_strtolower((string) $value), self::options(), true);

        return $index === false ? null : $index;
    }
}
