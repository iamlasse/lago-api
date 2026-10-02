<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\LagoContext;
use App\GraphQL\Exceptions\ExecutionError;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CurrentUserResolver
 * (app/graphql/resolvers/current_user_resolver.rb): "Retrieves currently
 * connected user" — raises `unauthorized` without a JWT.
 */
class CurrentUser
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        $user = LagoContext::currentUser($context);

        if ($user === null) {
            throw new ExecutionError('unauthorized', 'unauthorized', 'unauthorized');
        }

        return $user;
    }
}
