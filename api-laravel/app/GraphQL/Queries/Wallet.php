<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\Wallet as WalletModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::WalletResolver
 * (app/graphql/resolvers/wallet_resolver.rb): "Query a single wallet of an
 * organization" — `current_organization.wallets.find(id)`, so a wallet of
 * another organization answers the not_found envelope exactly like Rails.
 */
class Wallet
{
    /** Rails: BaseQuery::UUID_REGEX — the uuid attribute cast's valid shape. */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?WalletModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        // Rails: find_by/find with a non-uuid id answers the not_found
        // envelope (the uuid attribute cast never reaches a PG cast error);
        // guard the id shape before querying.
        $id = $args['id'] ?? null;

        if (! is_string($id) || preg_match(self::UUID_REGEX, $id) !== 1) {
            throw Errors::notFoundError('wallet');
        }

        $found = WalletModel::query()
            ->where('organization_id', $organization->id)
            ->find($id);

        if ($found === null) {
            throw Errors::notFoundError('wallet');
        }

        return $found;
    }
}
