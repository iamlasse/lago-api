<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WalletTransaction;
use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Builder;
use App\Models\WalletTransactionConsumption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' WalletTransactionConsumptionsQuery
 * (app/queries/wallet_transaction_consumptions_query.rb) — the consumption
 * edges of one wallet transaction: `direction: :consumptions` reads the
 * consumptions of an inbound transaction, `:fundings` the fundings of an
 * outbound one.
 */
class WalletTransactionConsumptionsQuery extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet_transaction_consumptions');

        $direction = $this->filters['direction'] ?? 'consumptions';

        /** @var WalletTransaction|null $walletTransaction */
        $walletTransaction = WalletTransaction::query()
            ->where('organization_id', $this->organization->id)
            ->where('id', $this->filters['wallet_transaction_id'] ?? null)
            ->first();

        if ($walletTransaction === null) {
            return $result->notFoundFailure('wallet_transaction');
        }

        // Rails: single_validation_failure!(field: :wallet,
        // error_code: "not_traceable") unless wallet.wallet.traceable?
        if (! (bool) ($walletTransaction->wallet->traceable ?? false)) {
            return $result->singleValidationFailure('not_traceable', 'wallet');
        }

        if ($direction === 'consumptions') {
            if ($walletTransaction->transaction_type !== WalletTransactionType::Inbound) {
                return $result->singleValidationFailure('invalid_transaction_type', 'transaction_type');
            }

            $consumptions = WalletTransactionConsumption::query()
                ->where('inbound_wallet_transaction_id', $walletTransaction->id)
                ->with(['outboundWalletTransaction.wallet']);
        } else {
            if ($walletTransaction->transaction_type !== WalletTransactionType::Outbound) {
                return $result->singleValidationFailure('invalid_transaction_type', 'transaction_type');
            }

            $consumptions = WalletTransactionConsumption::query()
                ->where('outbound_wallet_transaction_id', $walletTransaction->id)
                ->with(['inboundWalletTransaction.wallet']);
        }

        $result->wallet_transaction_consumptions = $this->paginate($consumptions);

        return $result;
    }

    /**
     * Rails: paginate + apply_consistent_ordering (created_at DESC, id ASC).
     */
    private function paginate(Builder $query): LengthAwarePaginator
    {
        $page = max(1, (int) ($this->pagination['page'] ?? 1));
        $limit = max(1, (int) ($this->pagination['limit'] ?? \App\GraphQL\Support\Page::DEFAULT_LIMIT));

        return $query->latest()->orderBy('id')->paginate(perPage: $limit, page: $page);
    }
}
