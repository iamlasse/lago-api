<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Wallet;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WalletTransaction;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionStatus;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\WalletTransactionCreditStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' WalletTransactionsQuery
 * (app/queries/wallet_transactions_query.rb) — always scoped to one wallet
 * of the organization; a missing wallet answers the not_found envelope
 * (resource "wallet"). Invalid enum filter values are ignored (Rails: the
 * `valid_status?` / `valid_transaction_type?` /
 * `valid_transaction_status?` guards skip the where), and the metadata
 * filter matches key/value pairs inside the jsonb column.
 */
class WalletTransactionsQuery extends BaseService
{
    /** Kaminari's default per page, used when the limit param is nil. */
    private const DEFAULT_PER_PAGE = 100;

    /** Rails: BaseQuery::UUID_REGEX — the uuid attribute cast's valid shape. */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __construct(
        private readonly Organization $organization,
        private readonly mixed $walletId = null,
        private readonly array $pagination = ['page' => null, 'limit' => null],
        private readonly array $filters = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet_transactions');

        $walletId = $this->walletId;

        // Rails: find_by on a uuid column answers nil (not_found) for a
        // non-uuid id instead of raising a PG cast error.
        if (is_string($walletId) && preg_match(self::UUID_REGEX, $walletId) !== 1) {
            return $result->notFoundFailure('wallet');
        }

        $wallet = Wallet::query()
            ->where('organization_id', $this->organization->id)
            ->where('id', $walletId)
            ->first();

        if ($wallet === null) {
            return $result->notFoundFailure('wallet');
        }

        // Rails iterates a WalletTransaction scope — unwrap the has-many.
        $walletTransactions = $wallet->walletTransactions()->getQuery();

        $walletTransactions = $this->withTransactionType($walletTransactions) ?? $walletTransactions;
        $walletTransactions = $this->withStatus($walletTransactions) ?? $walletTransactions;
        $walletTransactions = $this->withTransactionStatus($walletTransactions) ?? $walletTransactions;
        $walletTransactions = $this->withMetadata($walletTransactions) ?? $walletTransactions;

        // Rails: paginate then apply_consistent_ordering (created_at desc,
        // id asc tiebreak) — applied before the SQL executes here so the
        // ordering actually lands inside the LIMIT/OFFSET query.
        $walletTransactions = $walletTransactions
            ->latest('wallet_transactions.created_at')
            ->orderBy('wallet_transactions.id');

        $result->wallet_transactions = $this->paginate($walletTransactions);

        return $result;
    }

    private function withTransactionType(Builder $scope): ?Builder
    {
        $transactionType = WalletTransactionType::fromOption($this->filters['transaction_type'] ?? null);

        if ($transactionType === null) {
            return null;
        }

        return $scope->where('transaction_type', $transactionType);
    }

    private function withStatus(Builder $scope): ?Builder
    {
        $status = WalletTransactionStatus::fromOption($this->filters['status'] ?? null);

        if ($status === null) {
            return null;
        }

        return $scope->where('status', $status);
    }

    private function withTransactionStatus(Builder $scope): ?Builder
    {
        $transactionStatus = WalletTransactionCreditStatus::fromOption($this->filters['transaction_status'] ?? null);

        if ($transactionStatus === null) {
            return null;
        }

        return $scope->where('transaction_status', $transactionStatus);
    }

    /**
     * Rails: reduces a {key => value} hash into one EXISTS clause per pair
     * over `jsonb_array_elements(wallet_transactions.metadata)`; blank
     * values are skipped and the filter only applies for a present,
     * non-empty hash.
     *
     * @param  Builder<WalletTransaction>  $scope
     */
    private function withMetadata(Builder $scope): ?Builder
    {
        $metadata = $this->filters['metadata'] ?? null;

        if (! is_array($metadata) || $metadata === [] || array_is_list($metadata)) {
            return null;
        }

        foreach ($metadata as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $scope = $scope->whereRaw(
                'exists (select 1 from jsonb_array_elements(wallet_transactions.metadata) as pair '
                    .'where pair->>\'key\' = ? and pair->>\'value\' = ?)',
                [(string) $key, (string) $value],
            );
        }

        return $scope;
    }

    /**
     * Rails: `paginate` + kaminari — page/limit; nil (or blank) values fall
     * back to the defaults (page 1, `per_page` param else 100).
     */
    private function paginate(Builder $scope): LengthAwarePaginator
    {
        $pageParam = $this->pagination['page'] ?? null;
        $limitParam = $this->pagination['limit'] ?? null;

        $page = is_numeric((string) $pageParam) && (string) $pageParam !== '' ? max(1, (int) $pageParam) : 1;

        $perPage = self::DEFAULT_PER_PAGE;
        if ($limitParam !== null && $limitParam !== '') {
            $perPage = max(1, (int) $limitParam);
        }

        return $scope->paginate($perPage, ['*'], 'page', $page);
    }
}
