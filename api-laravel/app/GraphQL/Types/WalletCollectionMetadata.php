<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `WalletCollectionMetadata` type —
 * the port of Rails' Types::Wallets::Metadata (GraphqlPagination::
 * CollectionMetadataType + customer_active_wallets_count).
 *
 * The base pagination fields (currentPage/limitValue/totalPages/totalCount)
 * resolve off the metadata object the resolver built; the extra count is
 * computed by the wallets resolver (0 for an empty collection, else the
 * customer's active wallet count) and travels on the same object.
 */
class WalletCollectionMetadata
{
    public function customerActiveWalletsCount(object $metadata): int
    {
        return (int) ($metadata->customerActiveWalletsCount ?? 0);
    }
}
