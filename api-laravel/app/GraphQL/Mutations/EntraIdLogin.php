<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\Auth\EntraId\LoginService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Auth::EntraId::Login
 * (app/graphql/mutations/auth/entra_id/login.rb).
 */
class EntraIdLogin
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = LoginService::call(
            code: (string) ($input['code'] ?? ''),
            state: (string) ($input['state'] ?? ''),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'token' => $result->token,
            'user' => $result->user,
        ];
    }
}
