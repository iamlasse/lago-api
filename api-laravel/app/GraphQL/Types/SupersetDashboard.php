<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `SupersetDashboard` type (port of
 * Rails' Types::Superset::Dashboard::Object). The id / dashboard_title /
 * embedded_id / guest_token keys arrive snake_case on the resolver's
 * dashboard hashes and resolve through the attribute fallback; only
 * `superset_url` is computed.
 */
class SupersetDashboard
{
    /** Rails: `superset_url` — ENV["SUPERSET_URL"]. */
    public function supersetUrl(mixed $root): ?string
    {
        return config('lago.superset.url');
    }
}
