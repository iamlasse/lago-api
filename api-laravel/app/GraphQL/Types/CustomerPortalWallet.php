<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `CustomerPortalWallet` type (port of
 * Rails' Types::CustomerPortal::Wallets::Object): the same wallet payload as
 * the admin type minus the metadata/recurring-rules fields — the status enum
 * name resolver is inherited from Types\Wallet.
 */
class CustomerPortalWallet extends Wallet {}
