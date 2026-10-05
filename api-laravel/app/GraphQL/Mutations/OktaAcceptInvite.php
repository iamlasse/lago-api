<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\Auth\Okta\AcceptInviteService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Auth::Okta::AcceptInvite
 * (app/graphql/mutations/auth/okta/accept_invite.rb) — "Accepts a membership
 * invite with Okta Oauth".
 */
class OktaAcceptInvite
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = AcceptInviteService::call(
            inviteToken: (string) ($input['inviteToken'] ?? ''),
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
