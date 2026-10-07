<?php

declare(strict_types=1);

namespace App\GraphQL\Guards;

use App\GraphQL\Support\LagoContext;
use App\GraphQL\Exceptions\ExecutionError;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' RequiredOrganization concern
 * (app/graphql/concerns/required_organization.rb): a field requires a
 * current_organization, a current_membership, and that the membership actually
 * links the current_user to that organization.
 */
final class RequiredOrganization
{
    /** @throws ExecutionError */
    public static function authorize(GraphQLContext $context): void
    {
        throw_unless(LagoContext::currentOrganization($context), self::organizationError('Missing organization id'));

        $currentMembership = LagoContext::currentMembership($context);

        throw_unless($currentMembership, throw self::organizationError('Missing membership'));

        $currentUser = LagoContext::currentUser($context);
        if (
            $currentUser === null
            || $currentUser->id !== $currentMembership->user_id
            || $currentMembership->organization_id !== LagoContext::currentOrganization($context)->id
        ) {
            throw self::organizationError('Not in organization');
        }
    }

    /** Rails: extensions: {status: :forbidden, code: "forbidden"} */
    public static function organizationError(string $message): ExecutionError
    {
        return new ExecutionError($message, 'forbidden', 'forbidden');
    }
}
