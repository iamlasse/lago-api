<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use App\GraphQL\Execution\Errors;
use App\Services\PasswordResets\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PasswordResets::Create
 * (app/graphql/mutations/password_resets/create.rb): "Creates a new
 * password reset" — the payload is the reset row's id.
 */
class CreatePasswordReset
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $user = User::query()->where('email', $input['email'] ?? null)->first();

        $result = CreateService::call(user: $user);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return ['id' => $result->id];
    }
}
