<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Support\Utils\Version;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::VersionResolver
 * (app/graphql/resolvers/version_resolver.rb): "Retrieve the version of the
 * application" — the LAGO_VERSION file contents, or the environment when the
 * file is absent (Rails passes Rails.env as the default).
 */
class CurrentVersion
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        $version = Version::call(app()->environment());

        return (object) [
            // CurrentVersion field names on the wire
            'githubUrl' => $version->githubUrl,
            'number' => $version->number,
        ];
    }
}
