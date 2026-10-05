<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\Auth\GoogleService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Auth::Google::AcceptInvite
 * (app/graphql/mutations/auth/google/accept_invite.rb) — "Accepts a membership
 * invite with Google Oauth".
 */
class GoogleAcceptInvite
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = GoogleService::acceptInvite(
            (string) ($input['code'] ?? ''),
            (string) ($input['inviteToken'] ?? ''),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'membership' => $result->membership,
            'token' => $result->token,
            'user' => $result->user,
        ];
    }
}
