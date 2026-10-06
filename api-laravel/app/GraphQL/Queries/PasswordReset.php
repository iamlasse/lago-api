<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\Models\PasswordReset as PasswordResetModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PasswordResetResolver
 * (app/graphql/resolvers/password_reset_resolver.rb): "Query a password
 * reset by token" — only non-expired rows answer; Rails carries no
 * AuthenticableApiUser gate (the reset screen has no session yet).
 */
class PasswordReset
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): PasswordResetModel
    {
        $passwordReset = PasswordResetModel::query()
            ->where('expire_at', '>', now())
            ->where('token', $args['token'] ?? null)
            ->first();

        if ($passwordReset === null) {
            throw Errors::notFoundError('password_reset');
        }

        return $passwordReset;
    }
}
