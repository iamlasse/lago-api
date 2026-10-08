<?php

declare(strict_types=1);

namespace App\Services\ClickHouse\Logs;

use App\GraphQL\Support\Page;
use App\Models\Organization;
use App\Services\ClickHouse\Client;

/**
 * Shared body of the ClickHouse log listing queries — the port of the
 * `Clickhouse::{ActivityLog,ApiLog,SecurityLog}` ActiveRecord models
 * (app/models/clickhouse/*.rb, clickhouse connection) combined with the
 * kaminari pagination of the Rails `BaseQuery#paginate` helper as used by
 * app/queries/{activity,api,security}_logs_query.rb.
 *
 * ClickHouse is reached through the HTTP client (App\Services\ClickHouse\Client):
 * every column comes back as a string, Map(String, ...) columns come back as
 * JSON objects — the same stringly-typed rows the clickhouse-activerecord
 * adapter surfaces to the Rails models.
 *
 * Listing semantics are identical across the three Rails queries: scope to
 * the organization, `ORDER BY logged_at DESC`, kaminari `page`/`limit`
 * (count query + LIMIT/OFFSET; page 1 / limit 25 defaults).
 */
abstract class LogQuery
{
    public function __construct(protected ?Client $client = null)
    {
        $this->client ??= new Client;
    }

    /** The ClickHouse table backing this log. */
    abstract public function table(): string;

    /**
     * The AND-joined WHERE conditions of the listing (organization scope,
     * retention scope, and the applied filters) as SQL fragments.
     *
     * @param  array<string, mixed>  $filters  snake_case filter values
     * @return list<string>
     */
    abstract protected function conditions(Organization $organization, array $filters): array;

    /**
     * The kaminari-paginated, logged_at-desc listing — the port of the
     * Rails queries' `paginate` + `order(logged_at: :desc)` chain, returning
     * the frozen SDL's `*Collection` shape.
     *
     * @param  array<string, mixed>  $filters
     */
    public function page(Organization $organization, array $filters = [], ?int $page = null, ?int $limit = null): Page
    {
        [$page, $limit] = Page::normalizePageAndLimit($page, $limit);

        $where = $this->where($organization, $filters);

        $total = (int) ($this->client->selectValue(
            "SELECT count() FROM {$this->table()} WHERE {$where}",
        ) ?? 0);

        $offset = ($page - 1) * $limit;

        $rows = $this->client->selectRows(
            "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY logged_at DESC LIMIT {$limit} OFFSET {$offset}",
        );

        return new Page(
            collection: $rows,
            metadata: (object) [
                'currentPage' => $page,
                'limitValue' => $limit,
                // Kaminari reports at least one page, even for an empty set.
                'totalPages' => max(1, (int) ceil($total / $limit)),
                'totalCount' => $total,
            ],
        );
    }

    /**
     * The single-log lookup — the port of the Rails resolvers'
     * `current_organization.{activity,api,security}_logs.find_by!(...)`
     * (RecordNotFound → not_found_error at the resolver).
     */
    public function findBy(Organization $organization, string $keyColumn, string $keyValue): ?array
    {
        return $this->client->selectOne(
            "SELECT * FROM {$this->table()} WHERE organization_id = {$this->quote($organization->id)} "
                ."AND {$keyColumn} = {$this->quote($keyValue)} LIMIT 1",
        );
    }

    /** @param  array<string, mixed>  $filters */
    protected function where(Organization $organization, array $filters): string
    {
        return implode(' AND ', $this->conditions($organization, $filters));
    }

    /** Rails: `organization.audit_logs_period.present?` scope — logged_at >= period.days.ago. */
    protected function retentionCondition(Organization $organization): ?string
    {
        $period = $organization->audit_logs_period;

        if ($period === null) {
            return null;
        }

        return 'logged_at >= '.$this->quotedDatetime(now()->subDays((int) $period));
    }

    /** A quoted ClickHouse string literal (doubles the single quotes). */
    protected function quote(mixed $value): string
    {
        return "'".str_replace("'", "''", (string) $value)."'";
    }

    /** @param  list<string>  $values */
    protected function inList(string $column, array $values): string
    {
        return $column.' IN ('.implode(', ', array_map($this->quote(...), array_map(strval(...), $values))).')';
    }

    /** Rails: `scope.where(logged_at: from..)` / `..to` ISO8601 range filters. */
    protected function loggedAtRange(?mixed $fromDate, mixed $toDate): array
    {
        $conditions = [];

        if (! empty($fromDate)) {
            $conditions[] = 'logged_at >= '.$this->quotedDatetime($fromDate);
        }

        if (! empty($toDate)) {
            $conditions[] = 'logged_at <= '.$this->quotedDatetime($toDate);
        }

        return $conditions;
    }

    /** An end-of-day datetime for date-only filters, matching Rails' date semantics. */
    protected function quotedDatetime(mixed $value): string
    {
        return $this->quote(\Illuminate\Support\Facades\Date::parse($value)->utc()->format('Y-m-d H:i:s.v'));
    }
}
