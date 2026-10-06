<?php

declare(strict_types=1);

namespace App\GraphQL\Guards;

use App\GraphQL\Support\LagoContext;
use App\GraphQL\Exceptions\ExecutionError;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' AuthenticableCustomerPortalUser concern
 * (app/graphql/concerns/authenticable_customer_portal_user.rb): a customer
 * portal field can only resolve with a customer authenticated through the
 * portal token (`customer-portal-token` header →
 * `context[:customer_portal_user]`).
 */
final class CustomerPortalUser
{
    /** @throws ExecutionError */
    public static function authorize(GraphQLContext $context): void
    {
        if (LagoContext::customerPortalUser($context) === null) {
            throw self::unauthorizedError();
        }
    }

    /** Rails: GraphQL::ExecutionError.new("unauthorized", extensions: {status: :unauthorized, code: "unauthorized"}) */
    public static function unauthorizedError(): ExecutionError
    {
        return new ExecutionError('unauthorized', 'unauthorized', 'unauthorized');
    }
}
