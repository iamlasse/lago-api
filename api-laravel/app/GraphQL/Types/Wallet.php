<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Wallet as WalletModel;

/**
 * Field resolvers for the frozen SDL's `Wallet` type — the port of Rails'
 * Types::Wallets::Object. Fields that map 1:1 onto model attributes resolve
 * through LagoResolverProvider's snake_case fallback; only the computed
 * fields below need methods.
 */
class Wallet
{
    /** Rails: the status enum name — the column stores the integer position. */
    public function status(WalletModel $wallet): ?string
    {
        return $wallet->statusEnum()?->label();
    }

    /**
     * Rails: `object.metadata&.value` — the single ItemMetadata row's value
     * hash; the SDL wants a list of {key, value} objects.
     *
     * @return list<object>|null
     */
    public function metadata(WalletModel $wallet): ?array
    {
        $value = $wallet->metadata?->value;

        if ($value === null || ! is_array($value)) {
            return null;
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[] = (object) ['key' => (string) $key, 'value' => (string) $item];
        }

        return $out;
    }

    /**
     * Rails: `object.recurring_transaction_rules.active`.
     *
     * @return list<mixed>
     */
    public function recurringTransactionRules(WalletModel $wallet): array
    {
        return $wallet->recurringTransactionRules()->active()->get()->all();
    }

    /** Rails: `applies_to` is declared `method: :itself` — the wallet itself. */
    public function appliesTo(WalletModel $wallet): WalletModel
    {
        return $wallet;
    }
}
