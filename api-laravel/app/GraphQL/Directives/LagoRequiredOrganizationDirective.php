<?php

namespace App\GraphQL\Directives;

use App\GraphQL\Exceptions\ExecutionError;
use App\GraphQL\Support\LagoContext;
use Closure;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' RequiredOrganization concern
 * (app/graphql/concerns/required_organization.rb): the field requires a
 * current_organization, a current_membership, and that the membership actually
 * links the current_user to that organization — else `forbidden`.
 */
class LagoRequiredOrganizationDirective extends BaseDirective implements FieldMiddleware
{
    public static function definition(): string
    {
        return <<<'GRAPHQL'
"""
Requires the x-lago-organization header to resolve to one of the current
user's active memberships.
"""
directive @lagoRequiredOrganization on FIELD_DEFINITION
GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(fn (callable $resolver): Closure => function (mixed $root, array $args, GraphQLContext $context, mixed $resolveInfo) use ($resolver) {
            $currentUser = LagoContext::currentUser($context);
            $currentOrganization = LagoContext::currentOrganization($context);
            $currentMembership = LagoContext::currentMembership($context);

            if ($currentOrganization === null) {
                throw $this->organizationError('Missing organization id');
            }

            if ($currentMembership === null) {
                throw $this->organizationError('Missing membership');
            }

            if (
                $currentUser === null
                || $currentUser->id !== $currentMembership->user_id
                || $currentMembership->organization_id !== $currentOrganization->id
            ) {
                throw $this->organizationError('Not in organization');
            }

            return $resolver($root, $args, $context, $resolveInfo);
        });
    }

    private function organizationError(string $message): ExecutionError
    {
        // Rails: extensions: {status: :forbidden, code: "forbidden"}
        return new ExecutionError($message, 'forbidden', 'forbidden');
    }
}
