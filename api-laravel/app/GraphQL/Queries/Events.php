<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Event;
use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::EventsResolver
 * (app/graphql/resolvers/events_resolver.rb): "Query events of an
 * organization" — newest first, the limit capped at 1000.
 *
 * TODO(port): the ClickHouse branch (Clickhouse::EventsRaw ordered by
 * ingested_at) — the port always reads the Postgres `events` table by
 * created_at, Rails' else branch.
 */
class Events
{
    public const MAX_LIMIT = 1000;

    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $limit = $args['limit'] ?? null;
        $limit = ($limit !== null && $limit >= self::MAX_LIMIT) ? self::MAX_LIMIT : $limit;

        [$page, $perPage] = Page::normalizePageAndLimit($args['page'] ?? null, $limit);

        $events = Event::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)->latest();

        return Page::fromLengthAwarePaginator($events->paginate(perPage: $perPage, page: $page));
    }
}
