<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\Auth\Okta\AuthorizeService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Auth::Okta::Authorize
 * (app/graphql/mutations/auth/okta/authorize.rb) — returns the Okta
 * authorization URL for the email's domain.
 */
class OktaAuthorize
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $result = AuthorizeService::call(
            email: (string) ($input['email'] ?? ''),
            inviteToken: isset($input['inviteToken']) ? (string) $input['inviteToken'] : null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return ['url' => $result->url];
    }
}
