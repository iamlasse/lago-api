<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\Auth\GoogleService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Auth::Google::LoginUser
 * (app/graphql/mutations/auth/google/login_user.rb) — "Opens a session for an
 * existing user with Google Oauth".
 */
class GoogleLoginUser
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = GoogleService::login((string) ($input['code'] ?? ''));

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'token' => $result->token,
            'user' => $result->user,
        ];
    }
}
