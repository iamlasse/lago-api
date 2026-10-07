<?php

declare(strict_types=1);

namespace App\Services\WalletTransactions;

use App\Models\Wallet;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\InvoiceCustomSections\AttachToResourceService;
use App\Support\WalletCredit;
use App\Models\WalletTransaction;
use App\Enums\WalletTransactionSource;
use App\Models\Exceptions\StaleObjectError;
use App\Services\Wallets\Balance\IncreaseService;
use App\Services\Validators\WalletTransactionAmountLimits;

use function array_key_exists;

/**
 * Port of Rails' WalletTransactions::CreateFromParamsService
 * (app/services/wallet_transactions/create_from_params_service.rb) — the
 * public entrypoint (API / GraphQL / job): validates params, then creates
 * the paid, granted and/or voided transactions and moves the wallet
 * balances.
 *
 * Not ported (TODO(port)):
 * - InvoiceCustomSections::AttachToResourceService.
 * - Utils::ActivityLog.produce.
 */
class CreateFromParamsService extends BaseService
{
    public const MAX_WALLET_UPDATE_ATTEMPTS = 5;

    private int $updateAttempts = 0;

    private mixed $source = null;

    private mixed $metadata = null;

    private mixed $priority = null;

    public function __construct(
        private $organization,
        private array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        try {
            return $this->attempt();
        } catch (StaleObjectError $e) {
            if ($this->updateAttempts <= self::MAX_WALLET_UPDATE_ATTEMPTS) {
                usleep((int) (random_int(100000, 500000)));

                // Make sure the wallet is reloaded before retrying.
                $this->result()->current_wallet?->refresh();

                return $this->execute();
            }

            throw $e;
        }
    }

    private function result(): BaseResult
    {
        return static::makeResult(
            'current_wallet',
            'wallet_transactions',
            'payment_method',
            'voided_wallet_transaction',
        );
    }

    private function attempt(): BaseResult
    {
        $result = $this->result();

        $this->updateAttempts++;

        // Normalize metadata
        $params = $this->params;
        if (($params['metadata'] ?? null) === []) {
            $params['metadata'] = [];
        }

        if (! $this->valid($result)) {
            // NOTE: the validator sets result.current_wallet.
            return $result;
        }

        try {
            return $this->createTransactions($result, $params);
        } catch (\App\Services\Failures\FailedResult $e) {
            // Rails: `rescue BaseService::FailedResult => e; result.fail_with_error!(e)`.
            return $result->failWithError($e);
        }
    }

    private function createTransactions(BaseResult $result, array $params): BaseResult
    {

        $this->source = $params['source'] ?? WalletTransactionSource::Manual->value;
        $this->metadata = $params['metadata'] ?? [];
        $this->priority = $params['priority'] ?? 50;

        $invoiceRequiresSuccessfulPayment = null;
        if (array_key_exists('invoice_requires_successful_payment', $params)) {
            $invoiceRequiresSuccessfulPayment =
                filter_var($params['invoice_requires_successful_payment'], FILTER_VALIDATE_BOOLEAN);
        }

        /** @var Wallet $wallet */
        $wallet = $result->current_wallet;

        if ($invoiceRequiresSuccessfulPayment === null) {
            $invoiceRequiresSuccessfulPayment = (bool) $wallet->invoice_requires_successful_payment;
        }

        $walletTransactions = [];

        \Illuminate\Support\Facades\DB::transaction(function () use ($wallet, $params, $result, $invoiceRequiresSuccessfulPayment, &$walletTransactions): void {
            if (($params['paid_credits'] ?? null) !== null) {
                $transaction = $this->handlePaidCredits(
                    $wallet,
                    MoneyMath::roundTo((string) $params['paid_credits'], 5),
                    (bool) $invoiceRequiresSuccessfulPayment,
                    $result,
                );

                if ($transaction !== null) {
                    $walletTransactions[] = $transaction;
                }
            }

            if (($params['granted_credits'] ?? null) !== null) {
                $transaction = $this->handleGrantedCredits(
                    $wallet,
                    MoneyMath::roundTo((string) $params['granted_credits'], 5),
                    (bool) filter_var($params['reset_consumed_credits'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    $params['voided_invoice_id'] ?? null,
                );

                if ($transaction !== null) {
                    $walletTransactions[] = $transaction;
                }
            }

            if (($params['voided_credits'] ?? null) !== null || ($params['voided_transaction_id'] ?? '') !== '') {
                $transaction = $this->handleVoidedCredits($wallet, $result);

                if ($transaction !== null) {
                    $walletTransactions[] = $transaction;
                }
            }

            if (array_key_exists('invoice_custom_section', $params)) {
                foreach ($walletTransactions as $transaction) {
                    AttachToResourceService::call(resource: $transaction, params: $params);
                }
            }
        });

        $transactions = [];

        foreach ($walletTransactions as $walletTransaction) {
            if ($walletTransaction !== null) {
                $transactions[] = $walletTransaction;
            }
        }

        foreach ($transactions as $walletTransaction) {
            $walletTransaction->refresh();

            // Rails: SendWebhookJob.perform_later("wallet_transaction.created", wt)
            \App\Jobs\SendWebhookJob::performLater('wallet_transaction.created', $walletTransaction);
        }

        $result->wallet_transactions = $transactions;

        return $result;
    }

    /** Rails: `handle_paid_credits`. */
    private function handlePaidCredits(Wallet $wallet, string $creditsAmount, bool $invoiceRequiresSuccessfulPayment, BaseResult $result): ?WalletTransaction
    {
        if (bccomp($creditsAmount, '0', 5) === 0) {
            return null;
        }

        (new WalletTransactionAmountLimits(
            result: $result,
            wallet: $wallet,
            creditsAmount: $creditsAmount,
            ignoreValidation: $this->params['ignore_paid_top_up_limits'] ?? false,
        ))->raiseIfInvalid();

        $walletCredit = new WalletCredit(wallet: $wallet, creditAmount: $creditsAmount);

        $walletTransaction = CreateService::callBang(
            wallet: $wallet,
            walletCredit: $walletCredit,
            transactionParams: [
                'transaction_type' => 'inbound',
                'status' => 'pending',
                'source' => $this->source,
                'transaction_status' => 'purchased',
                'invoice_requires_successful_payment' => $invoiceRequiresSuccessfulPayment,
                'metadata' => $this->metadata,
                'priority' => $this->priority,
                'name' => $this->name(),
                'purchase_order_number' => $this->params['purchase_order_number'] ?? null,
                // TODO(port): payment_method param (PaymentMethod model).
            ],
        )->wallet_transaction;

        // Rails: BillPaidCreditJob.perform_after_commit(wallet_transaction,
        // Time.current.to_i) — the settlement bills the purchased credits
        // onto their invoice. The provider callbacks that enqueue this in
        // production are an M-later slice; the job itself is ported and
        // settles the transaction here.
        \App\Jobs\BillPaidCreditJob::dispatch($walletTransaction, now()->getTimestamp());

        return $walletTransaction;
    }

    /** Rails: `handle_granted_credits`. */
    private function handleGrantedCredits(Wallet $wallet, string $creditsAmount, bool $resetConsumedCredits, mixed $voidedInvoiceId): ?WalletTransaction
    {
        if (bccomp($creditsAmount, '0', 5) === 0) {
            return null;
        }

        $walletCredit = new WalletCredit(wallet: $wallet, creditAmount: $creditsAmount);

        $walletTransaction = CreateService::callBang(
            wallet: $wallet,
            walletCredit: $walletCredit,
            transactionParams: [
                'transaction_type' => 'inbound',
                'status' => 'settled',
                'settled_at' => now(),
                'source' => $this->source,
                'transaction_status' => 'granted',
                'metadata' => $this->metadata,
                'priority' => $this->priority,
                'name' => $this->name(),
                'purchase_order_number' => $this->params['purchase_order_number'] ?? null,
                'voided_invoice_id' => $voidedInvoiceId,
            ],
        )->wallet_transaction;

        IncreaseService::call(
            wallet: $wallet,
            walletTransaction: $walletTransaction,
            resetConsumedCredits: $resetConsumedCredits,
        );

        return $walletTransaction;
    }

    /** Rails: `handle_voided_credits`. */
    private function handleVoidedCredits(Wallet $wallet, BaseResult $result): ?WalletTransaction
    {
        // Resolved and validated upstream by ValidateService; nil means a
        // pool-wide void.
        $inboundWalletTransaction = $result->voided_wallet_transaction;

        $voidParams = [
            'metadata' => $this->metadata,
            'source' => $this->source,
            'priority' => $this->priority,
            'name' => $this->name(),
        ];

        if (($this->params['voided_credits'] ?? null) !== null) {
            $walletCredit = new WalletCredit(
                wallet: $wallet,
                creditAmount: MoneyMath::roundTo((string) $this->params['voided_credits'], 5),
                invoiceable: false,
            );

            return VoidService::callBang(
                wallet: $wallet,
                walletCredit: $walletCredit,
                inboundWalletTransaction: $inboundWalletTransaction,
                transactionParams: $voidParams,
            )->wallet_transaction;
        }

        // No amount given: void the grant's whole remaining, sized under the
        // lock in VoidService.
        return VoidService::callBang(
            wallet: $wallet,
            inboundWalletTransaction: $inboundWalletTransaction,
            voidRemaining: true,
            transactionParams: $voidParams,
        )->wallet_transaction;
    }

    private function name(): ?string
    {
        $name = $this->params['name'] ?? null;

        return ($name ?? '') !== '' ? $name : null;
    }

    private function valid(BaseResult $result): bool
    {
        // TODO(port): result.payment_method (PaymentMethod model).

        $validateParams = $this->params;
        $validateParams['organization'] = $this->organization;

        return (new ValidateService($result, $validateParams))->valid();
    }
}
