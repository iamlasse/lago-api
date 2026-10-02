<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use Closure;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;

/**
 * Port of Rails' RequiredOrganization concern, applied as a field-level guard:
 * the field requires the x-lago-organization header to resolve to one of the
 * current user's active memberships — else `forbidden`.
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
            RequiredOrganization::authorize($context);

            return $resolver($root, $args, $context, $resolveInfo);
        });
    }
}
