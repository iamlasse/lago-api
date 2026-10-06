<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\PasswordResets\ResetService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PasswordResets::Reset
 * (app/graphql/mutations/password_resets/reset.rb): "Reset password for
 * user and log in" — Types::Payloads::LoginUserType {token, user}.
 */
class ResetPassword
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = ResetService::call(
            token: $input['token'] ?? null,
            new_password: $input['newPassword'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result;
    }
}
