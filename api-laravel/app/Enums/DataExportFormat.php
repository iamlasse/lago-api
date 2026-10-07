<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * data_exports.format — integer column, Rails enum order is the stored value
 * (app/models/data_export.rb `enum :format, EXPORT_FORMATS`). Never
 * renumber: csv=0.
 */
enum DataExportFormat: int
{
    case Csv = 0;

    /** @return list<string> Rails' DataExport::EXPORT_FORMATS names, in order. */
    public static function options(): array
    {
        return ['csv'];
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
