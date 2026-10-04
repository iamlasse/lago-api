<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Wallet;
use App\Models\Customer;
use App\Enums\WalletStatus;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::WalletsResolver
 * (app/graphql/resolvers/wallets_resolver.rb): "Query wallets" — the
 * customer resolved by id from the current organization (unknown ids answer
 * the not_found envelope), kaminari pagination, optional status/ids
 * filters, ordered `status: :asc, created_at: :desc`, wrapped in the frozen
 * SDL's WalletCollection shape (`collection` + `metadata`).
 *
 * The WalletCollectionMetadata's `customerActiveWalletsCount` (Rails:
 * `object.first.customer.wallets.active.count`, 0 for an empty collection)
 * is computed here and travels on the metadata object;
 * App\GraphQL\Types\WalletCollectionMetadata reads it back.
 */
class Wallets
{
    /** Rails: BaseQuery::UUID_REGEX — the uuid attribute cast's valid shape. */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $customerId = $args['customerId'] ?? null;

        // A non-uuid id answers not_found (Rails: find raises RecordNotFound
        // without reaching a PG cast error on the uuid column).
        $customer = is_string($customerId) && preg_match(self::UUID_REGEX, $customerId) === 1
            ? Customer::query()
                ->where('organization_id', $organization->id)
                ->find($customerId)
            : null;

        if ($customer === null) {
            throw Errors::notFoundError('customer');
        }

        [$page, $limit] = Page::normalizePageAndLimit($args['page'] ?? null, $args['limit'] ?? null);

        $wallets = $customer->wallets()
            ->orderBy('status')
            ->latest('wallets.created_at');

        if (($args['status'] ?? null) !== null) {
            // The wire enum is the Rails enum name ("active"/"terminated");
            // the column stores the integer position.
            $wallets = $wallets->where(
                'status',
                $args['status'] === 'active' ? WalletStatus::Active->value : WalletStatus::Terminated->value,
            );
        }

        if (($args['ids'] ?? null) !== null && $args['ids'] !== []) {
            $wallets = $wallets->whereIn('id', $args['ids']);
        }

        $paginator = $wallets->paginate($limit, ['*'], 'page', $page);

        $result = Page::fromLengthAwarePaginator($paginator);

        // Rails: Types::Wallets::Metadata#customer_active_wallets_count —
        // 0 for an empty collection, else the customer's active wallet count.
        $result->metadata->customerActiveWalletsCount = count($result->collection) === 0
            ? 0
            : $customer->wallets()->active()->count();

        return $result;
    }
}
