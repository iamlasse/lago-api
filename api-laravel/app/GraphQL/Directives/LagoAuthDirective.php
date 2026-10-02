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
 * Port of Rails' AuthenticableApiUser concern
 * (app/graphql/concerns/authenticable_api_user.rb): the field raises
 * `unauthorized` when no current_user is present in the context.
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
            if (LagoContext::currentUser($context) === null) {
                // Rails: GraphQL::ExecutionError.new("unauthorized",
                //   extensions: {status: :unauthorized, code: "unauthorized"})
                throw new ExecutionError('unauthorized', 'unauthorized', 'unauthorized');
            }

            return $resolver($root, $args, $context, $resolveInfo);
        });
    }
}
