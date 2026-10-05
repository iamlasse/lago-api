<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\Auth\Okta\LoginService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Auth::Okta::Login
 * (app/graphql/mutations/auth/okta/login.rb).
 */
class OktaLogin
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
