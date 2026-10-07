<?php

declare(strict_types=1);

namespace App\Services\Wallets;

use App\Models\Wallet;
use App\Models\Customer;
use App\Enums\WalletStatus;
use Illuminate\Support\Str;
use App\Models\WalletTarget;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\InvoiceCustomSections\AttachToResourceService;
use App\Support\WalletCredit;
use Illuminate\Support\Facades\DB;
use App\Services\Validators\DecimalAmount;
use App\Services\Metadata\UpdateItemService;
use App\Services\Customers\UpdateCurrencyService;
use App\Services\Validators\WalletTransactionAmountLimits;

use function array_key_exists;

/**
 * Port of Rails' Wallets::CreateService (app/services/wallets/create_service.rb).
 *
 * Not ported (TODO(port)):
 * - recurring_transaction_rules (no RecurringTransactionRule model yet) —
 *   args are accepted and ignored; no RecurringTransactionRules::CreateService.
 * - InvoiceCustomSections::AttachToResourceService (invoice custom sections
 *   slice).
 * - BillingObjectConnections::AttachToResourceService — the
 *   multi_connection forbidden check and connection attach are skipped.
 * - schedule_top_up: wired — WalletTransactions\CreateJob carries the
 *   initial paid_credits / granted_credits after commit (the recurring
 *   rule's first grant waits on RecurringTransactionRules::CreateService).
 * - activity_loggable middleware.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet', 'billable_metric_identifiers', 'billable_metrics', 'payment_method');
        $params = $this->params;

        $result->billable_metric_identifiers = $this->billableMetricIdentifiers();
        $result->billable_metrics = $this->billableMetrics();
        // TODO(port): payment_method resolution (PaymentMethod model).
        $result->payment_method = null;

        // TODO(port): `return result.forbidden_failure! if
        // connections_requested? && organization_flag_disabled?(:multi_connection)`
        // — BillingObjectConnections slice.

        if (! $this->valid($result)) {
            return $result;
        }

        $customer = $params['customer'];
        $organizationId = $params['organization_id'];

        $code = $params['code'] ?? null;

        // only adjust generated code if it's taken
        if (($code ?? '') === '') {
            $code = self::parameterize($params['name'] ?? '') ?: 'default';

            $codeTaken = Wallet::query()
                ->where('organization_id', $organizationId)
                ->where('customer_id', $customer->id)
                ->where('status', WalletStatus::Active->value)
                ->where('code', $code)
                ->exists();

            if ($codeTaken) {
                $code .= '_'.now()->getTimestamp();
            }
        }

        $attributes = [
            'organization_id' => $organizationId,
            'customer_id' => $customer->id,
            'name' => $params['name'] ?? null,
            'code' => $code,
            'rate_amount' => $params['rate_amount'] ?? null,
            'expiration_at' => $params['expiration_at'] ?? null,
            'status' => WalletStatus::Active->value,
            'paid_top_up_min_amount_cents' => $params['paid_top_up_min_amount_cents'] ?? null,
            'paid_top_up_max_amount_cents' => $params['paid_top_up_max_amount_cents'] ?? null,
            'purchase_order_number' => $params['purchase_order_number'] ?? null,
            'traceable' => $this->traceable($customer),
        ];

        if (($params['priority'] ?? null) !== null) {
            $attributes['priority'] = $params['priority'];
        }

        if (array_key_exists('invoice_requires_successful_payment', $params)) {
            $attributes['invoice_requires_successful_payment'] =
                filter_var($params['invoice_requires_successful_payment'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('applies_to', $params) && array_key_exists('fee_types', (array) ($params['applies_to'] ?? []))) {
            $attributes['allowed_fee_types'] = $params['applies_to']['fee_types'];
        }

        if (array_key_exists('payment_method', $params)) {
            if (array_key_exists('payment_method_type', (array) ($params['payment_method'] ?? []))) {
                $attributes['payment_method_type'] = $params['payment_method']['payment_method_type'];
            }

            if (array_key_exists('payment_method_id', (array) ($params['payment_method'] ?? []))) {
                $attributes['payment_method_id'] = $params['payment_method']['payment_method_id'];
            }
        }

        if (($params['billing_entity_id'] ?? null) !== null || ($params['billing_entity_code'] ?? null) !== null) {
            $billingEntity = $this->billingEntity($customer);

            if ($billingEntity === null) {
                return $result->notFoundFailure('billing_entity');
            }

            $attributes['billing_entity_id'] = $billingEntity->id;
        }

        $wallet = new Wallet($attributes);

        try {
            DB::transaction(function () use ($wallet, $customer, $result): void {
                $currency = $this->params['currency'] ?? null;

                if (($currency ?? '') !== '' && ($customer->currency ?? '') === '') {
                    UpdateCurrencyService::callBang(customer: $customer, currency: $currency);
                }

                $wallet->currency = $currency ?? $wallet->customer->currency;

                $errors = $wallet->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $wallet->save();

                $this->validateWalletInitialAmount($wallet, $result);

                // TODO(port): recurring_transaction_rules creation
                // (RecurringTransactionRules::CreateService).
                AttachToResourceService::call(resource: $wallet, params: $this->params);

                // TODO(port): BillingObjectConnections::AttachToResourceService
                // (connections_requested?).

                foreach ($this->billableMetrics() as $billableMetric) {
                    WalletTarget::query()->create([
                        'wallet_id' => $wallet->id,
                        'billable_metric_id' => $billableMetric->id,
                        'organization_id' => $this->params['organization_id'],
                    ]);
                }

                if (array_key_exists('metadata', $this->params) && $this->params['metadata'] !== null) {
                    // Rails discards the inner result (plain .call).
                    UpdateItemService::call(owner: $wallet, value: $this->params['metadata'], partial: false);
                }

                $customer->flagWalletsForRefresh();
            });

            $result->wallet = $wallet;

            // Rails: SendWebhookJob.perform_after_commit("wallet.created", wallet)
            // — after_commit scheduling is not ported; dispatches immediately
            // (same as the other ported services).
            \App\Jobs\SendWebhookJob::performLater('wallet.created', $wallet);

            // Rails: schedule_top_up — WalletTransactions::CreateJob
            // .perform_after_commit(organization_id:, params:) with the
            // initial paid/granted credits (the recurring rule's first grant
            // waits on RecurringTransactionRules::CreateService).
            $this->scheduleTopUp($wallet);

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /** Rails: `name.to_s.parameterize(separator: "_")`. */
    private static function parameterize(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }

        return Str::slug($name, '_');
    }

    /** Rails: `schedule_top_up` — enqueues the wallet's initial top-up. */
    private function scheduleTopUp(Wallet $wallet): void
    {
        $paidCredits = $this->paidCredits();
        $grantedCredits = $this->grantedCredits();

        $positive = fn (mixed $amount): bool => $amount !== null
            && $amount !== ''
            && bccomp((string) $amount, '0', 5) === 1;

        if (! $positive($paidCredits) && ! $positive($grantedCredits)) {
            return;
        }

        dispatch(new \App\Jobs\WalletTransactions\CreateJob(organizationId: (string) $this->params['organization_id'], params: [
            'wallet_id' => $wallet->id,
            'paid_credits' => $paidCredits,
            'granted_credits' => $grantedCredits,
            'source' => 'manual',
            'metadata' => $this->params['transaction_metadata'] ?? null,
            'name' => $this->params['transaction_name'] ?? null,
            'priority' => $this->params['transaction_priority'] ?? null,
            'ignore_paid_top_up_limits' => $this->params['ignore_paid_top_up_limits_on_creation'] ?? null,
            // TODO(port): recurring_transaction_rule&.resolved_purchase_order_number.
            'purchase_order_number' => $wallet->purchase_order_number,
        ]));
    }

    private function valid(BaseResult $result): bool
    {
        return (new ValidateService($result, $this->params))->valid();
    }

    /** Rails: `validate_wallet_initial_amount!`. */
    private function validateWalletInitialAmount(Wallet $wallet, BaseResult $result): void
    {
        $this->rejectCreditsRoundingToZero($wallet, $result);

        if (! DecimalAmount::validPositiveAmount($this->paidCredits())) {
            return;
        }

        (new WalletTransactionAmountLimits(
            result: $result,
            wallet: $wallet,
            creditsAmount: $this->paidCredits(),
            ignoreValidation: $this->params['ignore_paid_top_up_limits_on_creation'] ?? false,
        ))->raiseIfInvalid();
    }

    /** Rails: `reject_credits_rounding_to_zero!`. */
    private function rejectCreditsRoundingToZero(Wallet $wallet, BaseResult $result): void
    {
        foreach (['paid_credits' => $this->paidCredits(), 'granted_credits' => $this->grantedCredits()] as $field => $credits) {
            if (! WalletCredit::roundsToZero($wallet, $credits)) {
                continue;
            }

            $result->singleValidationFailure('amount_rounds_to_zero', $field);
            $result->raiseIfError();
        }
    }

    /** Rails: `traceable?` — every active wallet of the customer is traceable. */
    private function traceable(Customer $customer): bool
    {
        return ! $customer->wallets()->active()->where('traceable', false)->exists();
    }

    /** @return list<string> */
    private function billableMetricIdentifiers(): array
    {
        $appliesTo = $this->params['applies_to'] ?? null;

        if ($appliesTo === null || $appliesTo === []) {
            return [];
        }

        $key = $this->apiContext() ? 'billable_metric_codes' : 'billable_metric_ids';

        if ((($appliesTo[$key] ?? null)) === null || $appliesTo[$key] === []) {
            return [];
        }

        return array_values(array_unique(array_values(array_filter($appliesTo[$key], fn ($v) => $v !== null))));
    }

    private function billableMetrics(): mixed
    {
        $identifiers = $this->billableMetricIdentifiers();

        if ($identifiers === []) {
            return [];
        }

        $query = \App\Models\BillableMetric::query()->where('organization_id', $this->params['organization_id'] ?? null);

        if ($this->apiContext()) {
            return $query->whereIn('code', $identifiers)->get();
        }

        return $query->whereIn('id', $identifiers)->get();
    }

    private function billingEntity(Customer $customer): ?\App\Models\BillingEntity
    {
        $scope = $customer->organization->billingEntities();

        if (($this->params['billing_entity_id'] ?? null) !== null) {
            return $scope->where('id', $this->params['billing_entity_id'])->first();
        }

        if (($this->params['billing_entity_code'] ?? null) !== null) {
            return $scope->where('code', $this->params['billing_entity_code'])->first();
        }

        return null;
    }

    private function paidCredits(): mixed
    {
        return $this->params['paid_credits'] ?? null;
    }

    private function grantedCredits(): mixed
    {
        return $this->params['granted_credits'] ?? null;
    }
}
