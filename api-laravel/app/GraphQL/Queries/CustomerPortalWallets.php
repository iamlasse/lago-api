<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\CustomerPortalUser;
use App\Enums\WalletStatus;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomerPortal::WalletsResolver
 * (app/graphql/resolvers/customer_portal/wallets_resolver.rb): "Query
 * wallets" — the portal customer's own wallets, the active ones unless a
 * status filter is given, kaminari-paginated, ordered `priority ASC,
 * created_at ASC`, wrapped in the frozen SDL's CustomerPortalWalletCollection
 * shape.
 */
class CustomerPortalWallets
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        [$page, $limit] = Page::normalizePageAndLimit($args['page'] ?? null, $args['limit'] ?? null);

        $wallets = $customer->wallets();

        // Rails: status.present? ? wallets.where(status:) : wallets.active
        if (($args['status'] ?? null) !== null) {
            // The wire enum is the Rails enum name ("active"/"terminated");
            // the column stores the integer position.
            $wallets = $wallets->where(
                'status',
                $args['status'] === 'active' ? WalletStatus::Active->value : WalletStatus::Terminated->value,
            );
        } else {
            $wallets = $wallets->active();
        }

        $paginator = $wallets
            ->orderBy('priority')
            ->orderBy('created_at')
            ->paginate($limit, ['*'], 'page', $page);

        return Page::fromLengthAwarePaginator($paginator);
    }
}
