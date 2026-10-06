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

    /**
     * The mrr variant: Rails renders THE SAME wall-clock month as
     * "2025-04-01T00:00:00.000+00:00" there. The Postgres type path differs
     * per endpoint — mrr's generate-series upper bound is
     * `date_trunc('month', now())` (timestamptz) while the other four
     * analytics models bound with CURRENT_DATE (timestamp without time
     * zone) — and ActiveRecord serializes the two types differently
     * (offset form vs Z form). PDO hands the port an undistinguishable
     * "YYYY-MM-DD HH:MM:SS" string, so the per-endpoint format is pinned at
     * the serializer. Goldens are truth; do not "normalize" the two.
     */
    protected function serializeMonthWithOffset(mixed $month): ?string
    {
        if ($month === null) {
            return null;
        }

        return \Carbon\CarbonImmutable::parse((string) $month, 'UTC')->utc()->format('Y-m-d\TH:i:s.vP');
    }

    /** Rails: `model[...]&.to_i`. */
    protected function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
