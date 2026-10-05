<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Services\Auth\EntraId\AuthorizeService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Auth::EntraId::Authorize
 * (app/graphql/mutations/auth/entra_id/authorize.rb) — returns the Entra ID
 * authorization URL for the email's domain.
 */
class EntraIdAuthorize
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
