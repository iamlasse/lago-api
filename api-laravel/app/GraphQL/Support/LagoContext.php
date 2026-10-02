<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use Illuminate\Http\Request;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Bridges request-level auth state (resolved by the AuthenticateUser route
 * middleware, mirroring Rails' AuthenticableUser controller concern) into
 * GraphQL resolvers.
 *
 * Lighthouse's HttpGraphQLContext wraps the Request, so the resolved values
 * travel as request attributes — no container binding or service provider
 * changes needed.
 */
final class LagoContext
{
    public const CURRENT_USER = 'lago.current_user';

    public const CURRENT_ORGANIZATION = 'lago.current_organization';

    public const CURRENT_MEMBERSHIP = 'lago.current_membership';

    public const LOGIN_METHOD = 'lago.login_method';

    public const PERMISSIONS = 'lago.permissions';

    public static function set(
        Request $request,
        ?object $currentUser,
        ?object $currentOrganization,
        ?object $currentMembership,
        ?string $loginMethod,
        ?array $permissions,
    ): void {
        $request->attributes->set(self::CURRENT_USER, $currentUser);
        $request->attributes->set(self::CURRENT_ORGANIZATION, $currentOrganization);
        $request->attributes->set(self::CURRENT_MEMBERSHIP, $currentMembership);
        $request->attributes->set(self::LOGIN_METHOD, $loginMethod);
        $request->attributes->set(self::PERMISSIONS, $permissions);
    }

    public static function currentUser(GraphQLContext $context): ?object
    {
        return self::attribute($context, self::CURRENT_USER);
    }

    public static function currentOrganization(GraphQLContext $context): ?object
    {
        return self::attribute($context, self::CURRENT_ORGANIZATION);
    }

    public static function currentMembership(GraphQLContext $context): ?object
    {
        return self::attribute($context, self::CURRENT_MEMBERSHIP);
    }

    public static function loginMethod(GraphQLContext $context): ?string
    {
        return self::attribute($context, self::LOGIN_METHOD);
    }

    private static function attribute(GraphQLContext $context, string $key): mixed
    {
        return $context->request()?->attributes->get($key);
    }
}
