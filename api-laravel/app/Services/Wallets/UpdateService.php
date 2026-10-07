<?php

declare(strict_types=1);

namespace App\Services\Wallets;

use App\Models\Wallet;
use App\Models\WalletTarget;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\InvoiceCustomSections\AttachToResourceService;
use Illuminate\Support\Facades\DB;
use App\Services\Metadata\UpdateItemService;

use function count;
use function array_key_exists;

/**
 * Port of Rails' Wallets::UpdateService (app/services/wallets/update_service.rb).
 *
 * Not ported (TODO(port)):
 * - recurring_transaction_rules (RecurringTransactionRules::UpdateService
 *   and the per-rule amount-limits validation) — args accepted and ignored.
 * - InvoiceCustomSections::AttachToResourceService.
 * - BillingObjectConnections::AttachToResourceService — the multi_connection
 *   forbidden check and the connection attach are skipped.
 * - Customers::RefreshWalletJob re-enqueue after a refresh-relevant change —
 *   the RefreshWallets pipeline depends on Invoices::CustomerUsageService
 *   (usage aggregation slice); only the awaiting_wallet_refresh flag is set.
 * - activity_loggable middleware.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?Wallet $wallet,
        private readonly array $params,
        private readonly bool $partialMetadata = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet', 'billable_metrics', 'billable_metric_identifiers', 'payment_method');
        $wallet = $this->wallet;
        $params = $this->params;

        if ($wallet === null) {
            return $result->notFoundFailure('wallet');
        }

        if ($wallet->isTerminated()) {
            return $result->singleValidationFailure('wallet_is_terminated', 'wallet_id');
        }

        // TODO(port): `return result.forbidden_failure! if
        // connections_requested? && organization_flag_disabled?(:multi_connection)`.

        if (! $this->validExpirationAt($result)) {
            return $result;
        }

        // TODO(port): valid_recurring_transaction_rules? / valid_limitations?
        // sets result.billable_metrics + identifiers below (same as Rails).
        $result->billable_metrics = $this->billableMetrics();
        $result->billable_metric_identifiers = $this->billableMetricIdentifiers();

        if (! (new ValidateLimitationsService($result, $params))->valid()) {
            return $result;
        }

        // TODO(port): valid_payment_method? (PaymentMethods::ValidateService).
        // TODO(port): valid_connections? (BillingObjectConnections::ValidateService).

        if ($this->billingEntityParamSent()) {
            if ($this->billingEntityValueProvided() && $this->billingEntity($wallet) === null) {
                return $result->notFoundFailure('billing_entity');
            }

            $wallet->billing_entity_id = $this->billingEntity($wallet)?->id;
        }

        try {
            $walletTargetsChanged = false;

            DB::transaction(function () use ($wallet, $params, $result, &$walletTargetsChanged): void {
                if (array_key_exists('name', $params)) {
                    $wallet->name = $params['name'];
                }

                if (($params['code'] ?? null) !== null) {
                    $wallet->code = $params['code'];
                }

                if (($params['priority'] ?? null) !== null) {
                    $wallet->priority = $params['priority'];
                }

                if (array_key_exists('expiration_at', $params)) {
                    $wallet->expiration_at = $params['expiration_at'];
                }

                if (array_key_exists('purchase_order_number', $params)) {
                    $wallet->purchase_order_number = $params['purchase_order_number'];
                }

                if (($params['invoice_requires_successful_payment'] ?? null) !== null) {
                    $wallet->invoice_requires_successful_payment =
                        filter_var($params['invoice_requires_successful_payment'], FILTER_VALIDATE_BOOLEAN);
                }

                if (($params['paid_top_up_min_amount_cents'] ?? null) !== null) {
                    $wallet->paid_top_up_min_amount_cents = $params['paid_top_up_min_amount_cents'];
                }

                if (($params['paid_top_up_max_amount_cents'] ?? null) !== null) {
                    $wallet->paid_top_up_max_amount_cents = $params['paid_top_up_max_amount_cents'];
                }

                // TODO(port): `if params[:recurring_transaction_rules] && License.premium?`
                // — RecurringTransactionRules::UpdateService + validate_rule!.

                if (array_key_exists('applies_to', $params) && array_key_exists('fee_types', (array) ($params['applies_to'] ?? []))) {
                    $wallet->allowed_fee_types = $params['applies_to']['fee_types'];
                }

                // TODO(port): payment_method_type / payment_method_id
                // assignment (PaymentMethod model) — Rails copies the params
                // keys through when params.key?(:payment_method).

                if ($this->billableMetricLimitationsSent()) {
                    $walletTargetsChanged = $this->processBillableMetrics($wallet);
                }

                $errors = $wallet->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $wallet->save();

                if (array_key_exists('metadata', $params)) {
                    UpdateItemService::callBang(
                        owner: $wallet,
                        value: $params['metadata'],
                        partial: $this->partialMetadata,
                    );
                }

                if ($this->needsRefresh($wallet, $walletTargetsChanged)) {
                    $wallet->customer->flagWalletsForRefresh();
                    // TODO(port): Customers::RefreshWalletJob
                    // .perform_after_commit(wallet.customer) — the refresh
                    // pipeline (Customers::RefreshWalletsService →
                    // Invoices::CustomerUsageService) is a later slice; the
                    // flag above schedules the recalculation.
                }

                AttachToResourceService::call(resource: $wallet, params: $this->params);

                // TODO(port): BillingObjectConnections::AttachToResourceService.

                // Rails: SendWebhookJob.perform_after_commit("wallet.updated", wallet)
                \App\Jobs\SendWebhookJob::performLater('wallet.updated', $wallet);
            });

            $result->wallet = $wallet;

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    private function validExpirationAt(BaseResult $result): bool
    {
        if (\App\Services\Validators\ExpirationDate::valid($this->params['expiration_at'] ?? null)) {
            return true;
        }

        $result->singleValidationFailure('invalid_date', 'expiration_at');

        return false;
    }

    /**
     * @return list<string>
     */
    private function billableMetricIdentifiers(): array
    {
        $appliesTo = $this->params['applies_to'] ?? null;

        if ($appliesTo === null || $appliesTo === []) {
            return [];
        }

        $key = $this->billableMetricIdentifiersKey();

        if ((($appliesTo[$key] ?? null)) === null || $appliesTo[$key] === []) {
            return [];
        }

        return array_values(array_unique(array_values(array_filter($appliesTo[$key], fn ($v) => $v !== null))));
    }

    private function billableMetricIdentifiersKey(): string
    {
        return $this->apiContext() ? 'billable_metric_codes' : 'billable_metric_ids';
    }

    private function billableMetrics(): mixed
    {
        $identifiers = $this->billableMetricIdentifiers();

        if ($identifiers === []) {
            return [];
        }

        $query = \App\Models\BillableMetric::query()
            ->where('organization_id', $this->wallet->organization_id ?? null);

        if ($this->apiContext()) {
            return $query->whereIn('code', $identifiers)->get();
        }

        return $query->whereIn('id', $identifiers)->get();
    }

    /**
     * Rails: `process_billable_metrics` — creates the missing targets and
     * removes the stale ones. Returns whether anything changed.
     */
    private function processBillableMetrics(Wallet $wallet): bool
    {
        $changed = false;

        $existingWalletBillableMetricIds = $wallet->walletTargets()->pluck('billable_metric_id')->all();

        foreach ($this->billableMetrics() as $billableMetric) {
            if (in_array($billableMetric->id, $existingWalletBillableMetricIds, true)) {
                continue;
            }

            WalletTarget::query()->create([
                'wallet_id' => $wallet->id,
                'billable_metric_id' => $billableMetric->id,
                'organization_id' => $wallet->organization_id,
            ]);

            $changed = true;
        }

        if ($existingWalletBillableMetricIds !== []) {
            $keptIds = collect($this->billableMetrics())->pluck('id')->all();
            $notNeededIds = array_diff($existingWalletBillableMetricIds, $keptIds);

            foreach ($notNeededIds as $billableMetricId) {
                $target = WalletTarget::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('billable_metric_id', $billableMetricId)
                    ->where('organization_id', $wallet->organization_id)
                    ->first();

                if ($target === null) {
                    continue;
                }

                $target->delete();
                $changed = true;
            }
        }

        return $changed;
    }

    /** Rails: `needs_refresh?`. */
    private function needsRefresh(Wallet $wallet, bool $walletTargetsChanged): bool
    {
        if ($walletTargetsChanged) {
            return true;
        }

        $changedKeys = array_keys($wallet->getChanges());

        return count(array_intersect($changedKeys, Wallet::REFRESH_RELEVANT_ATTRIBUTES)) > 0;
    }

    /**
     * Billable metric limitations are only processed when the payload
     * explicitly carries the identifiers key: omitting it leaves the
     * existing wallet targets untouched, while sending an empty array still
     * clears them.
     */
    private function billableMetricLimitationsSent(): bool
    {
        if (($this->params['applies_to'] ?? null) === null) {
            return false;
        }

        return array_key_exists($this->billableMetricIdentifiersKey(), (array) $this->params['applies_to']);
    }

    private function billingEntityParamSent(): bool
    {
        return array_key_exists('billing_entity_id', $this->params)
            || array_key_exists('billing_entity_code', $this->params);
    }

    private function billingEntityValueProvided(): bool
    {
        return ($this->params['billing_entity_id'] ?? null) !== null
            || ($this->params['billing_entity_code'] ?? null) !== null;
    }

    private function billingEntity(Wallet $wallet): ?\App\Models\BillingEntity
    {
        $scope = $wallet->customer->organization->billingEntities();

        if (($this->params['billing_entity_id'] ?? null) !== null) {
            return $scope->where('id', $this->params['billing_entity_id'])->first();
        }

        if (($this->params['billing_entity_code'] ?? null) !== null) {
            return $scope->where('code', $this->params['billing_entity_code'])->first();
        }

        return null;
    }
}
