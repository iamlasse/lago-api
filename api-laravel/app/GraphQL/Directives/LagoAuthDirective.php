<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use App\GraphQL\Guards\AuthenticableApiUser;
use Closure;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' AuthenticableApiUser concern, applied as a field-level guard:
 * the field raises `unauthorized` when no current_user is present in the
 * context.
 */
class LagoAuthDirective extends BaseDirective implements FieldMiddleware
{
    public static function definition(): string
    {
        return <<<'GRAPHQL'
"""
Requires a signed-in user (JWT) for this field.
"""
directive @lagoAuth on FIELD_DEFINITION
GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(fn (callable $resolver): Closure => function (mixed $root, array $args, GraphQLContext $context, mixed $resolveInfo) use ($resolver) {
            AuthenticableApiUser::authorize($context);

            return $resolver($root, $args, $context, $resolveInfo);
        });
    }
}
