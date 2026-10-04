<?php

declare(strict_types=1);

namespace App\Serializers\V1\Analytics;

/**
 * Port of Rails' V1::Analytics::* serializers' shared shape — unlike the
 * model-backed serializers these wrap plain analytics result ROWS
 * (Analytics::Base returns `result.to_a` — arrays, not records), so they
 * stand beside ModelSerializer instead of extending it; the
 * Api::V1::Analytics::BaseController renders the collection under
 * `controller_name` the way Rails' CollectionSerializer does.
 */
abstract class BaseSerializer
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function __construct(protected readonly array $row) {}

    /** @return array<string, mixed> */
    abstract public function serialize(): array;

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = (new static($row))->serialize();
        }

        return $out;
    }

    /**
     * Rails renders the raw `month` (a TimeWithZone from DATE_TRUNC) through
     * the Rails JSON encoder: iso8601 with millisecond precision
     * ("2025-04-01T00:00:00.000Z").
     */
    protected function serializeMonth(mixed $month): ?string
    {
        if ($month === null) {
            return null;
        }

        return \Carbon\CarbonImmutable::parse((string) $month, 'UTC')->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Rails: `model[...]&.to_i`. */
    protected function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
