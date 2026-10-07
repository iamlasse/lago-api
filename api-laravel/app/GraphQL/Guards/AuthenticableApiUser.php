<?php

declare(strict_types=1);

namespace App\GraphQL\Guards;

use App\GraphQL\Support\LagoContext;
use App\GraphQL\Exceptions\ExecutionError;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' AuthenticableApiUser concern
 * (app/graphql/concerns/authenticable_api_user.rb): a field can only resolve
 * with a signed-in user.
 */
final class AuthenticableApiUser
{
    /** @throws ExecutionError */
    public static function authorize(GraphQLContext $context): void
    {
        throw_unless(LagoContext::currentUser($context), self::unauthorizedError());
    }

    /** Rails: GraphQL::ExecutionError.new("unauthorized", extensions: {status: :unauthorized, code: "unauthorized"}) */
    public static function unauthorizedError(): ExecutionError
    {
        return new ExecutionError('unauthorized', 'unauthorized', 'unauthorized');
    }
}
