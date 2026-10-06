<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\Services\Auth\GoogleService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Auth::Google::AuthUrlResolver
 * (app/graphql/resolvers/auth/google/auth_url_resolver.rb): "Get Google
 * auth url."
 */
class GoogleAuthUrl
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        $result = GoogleService::authorizeUrl();

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails: `result` (Types::Auth::Google::AuthUrl reads `.url`).
        return (object) ['url' => $result->url];
    }
}
