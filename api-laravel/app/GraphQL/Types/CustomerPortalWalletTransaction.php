<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `CustomerPortalWalletTransaction`
 * type (port of Rails' Types::CustomerPortal::WalletTransactions::Object):
 * the same enum/bigdecimals resolvers as the admin type (inherited); the
 * `wallet` field resolves the relation, which then renders through the
 * CustomerPortalWallet type.
 */
class CustomerPortalWalletTransaction extends WalletTransaction {}
