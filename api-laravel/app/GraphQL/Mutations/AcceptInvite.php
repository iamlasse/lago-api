<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\Invites\AcceptService;
use App\Support\Organizations\AuthenticationMethods;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invites::Accept
 * (app/graphql/mutations/invites/accept.rb): "Accepts a new Invite" — the
 * email/password variant (SSO accepts ride on their own mutations).
 */
class AcceptInvite
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = AcceptService::call(
            token: $input['token'] ?? null,
            password: $input['password'] ?? null,
            login_method: AuthenticationMethods::EMAIL_PASSWORD,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result;
    }
}
