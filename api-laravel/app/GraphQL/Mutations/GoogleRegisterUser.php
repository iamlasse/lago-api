<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\Auth\GoogleService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Auth::Google::RegisterUser
 * (app/graphql/mutations/auth/google/register_user.rb) — "Register a new user
 * with Google Oauth".
 */
class GoogleRegisterUser
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = GoogleService::registerUser(
            (string) ($input['code'] ?? ''),
            (string) ($input['organizationName'] ?? ''),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'membership' => $result->membership,
            'organization' => $result->organization,
            'token' => $result->token,
            'user' => $result->user,
        ];
    }
}
