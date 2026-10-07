<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Auth\Superset\GuestTokenService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Superset::CreateGuestToken
 * (app/graphql/mutations/superset/create_guest_token.rb): "Mint a fresh
 * Superset guest token for a single dashboard".
 *
 * TODO(port): Rails also gates the mutation behind the `analytics:view`
 * permission (REQUIRED_PERMISSION); the permission port is pending (see
 * graphql/FULL_SCHEMA_NOTES.md item 3).
 */
class CreateSupersetGuestToken
{
    /**
     * @return array{guest_token: mixed} the SupersetGuestToken payload
     *
     * @throws Errors
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = GuestTokenService::call(
            organization: LagoContext::currentOrganization($context),
            dashboardId: (string) ($args['dashboardId'] ?? $args['dashboard_id'] ?? ''),
            user: [],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return ['guest_token' => $result->guest_token];
    }
}
