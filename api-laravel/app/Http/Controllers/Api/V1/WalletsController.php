<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Wallet;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Queries\WalletsQuery;
use Illuminate\Http\JsonResponse;
use App\Services\Wallets\CreateService;
use App\Services\Wallets\UpdateService;
use App\Serializers\V1\WalletSerializer;
use App\Exceptions\Api\NotFoundException;
use App\Services\Wallets\TerminateService;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' Api::V1::WalletsController (app/controllers/api/v1/
 * wallets_controller.rb) — the WalletActions concern's wallet_create /
 * wallet_update / wallet_terminate / wallet_show / wallet_index inline.
 *
 * Not ported (out of scope for this slice — dependencies do not exist yet):
 * - the nested wallets/:id/metadata subresource (Metadata::ItemMetadata
 *   controller slice);
 * - recurring_transaction_rules persistence (no RecurringTransactionRule
 *   model) and invoice custom sections / connections attach — the params
 *   are accepted (Rails' permit list) and the services TODO them.
 */
class WalletsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'wallet';

    public function create(Request $request): JsonResponse
    {
        return $this->renderWalletAction(
            CreateService::call(params: $this->createParams($request, $this->customer($request))),
        );
    }

    public function update(Request $request): JsonResponse
    {
        $wallet = $this->findWallet($request);

        return $this->renderWalletAction(
            UpdateService::call(wallet: $wallet, params: $this->updateParams($request)),
        );
    }

    public function terminate(Request $request): JsonResponse
    {
        $wallet = $this->findWallet($request);

        return $this->renderWalletAction(
            TerminateService::call(wallet: $wallet),
        );
    }

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->findWallet($request);

        if ($wallet === null) {
            throw new NotFoundException('wallet');
        }

        return $this->renderWallet($wallet);
    }

    public function index(Request $request): JsonResponse
    {
        $billingEntityIds = null;

        $billingEntityCodes = $request->query('billing_entity_codes');

        if ($billingEntityCodes !== null && $billingEntityCodes !== '' && $billingEntityCodes !== []) {
            $codes = is_array($billingEntityCodes) ? $billingEntityCodes : [$billingEntityCodes];

            $billingEntities = $this->currentOrganization()
                ->allBillingEntities()
                ->whereIn('code', $codes)
                ->get();

            if ($billingEntities->count() !== count(array_unique($codes))) {
                throw new NotFoundException('billing_entity');
            }

            $billingEntityIds = $billingEntities->pluck('id')->all();
        }

        // Port of `params.permit(:external_customer_id, :currency,
        // billing_entity_codes: [])` — a scalar sent where the array
        // `billing_entity_codes` is declared is dropped entirely.
        $externalCustomerId = $request->query('external_customer_id');
        $currency = $request->query('currency');

        $result = WalletsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: array_filter([
                'external_customer_id' => $externalCustomerId,
                'currency' => $currency,
                'billing_entity_ids' => $billingEntityIds,
            ], fn ($value): bool => $value !== null),
        );

        if ($result->success()) {
            return $this->renderWalletCollection($result->wallets);
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /** Rails: `current_organization.wallets.find_by(id: params[:id])`. */
    private function findWallet(Request $request): ?Wallet
    {
        return Wallet::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('id', $request->route('id'))
            ->first();
    }

    /**
     * Port of WalletActions#wallet_create / #wallet_update /
     * #wallet_terminate — a failed result renders the error envelope.
     */
    private function renderWalletAction(\App\Services\BaseResult $result): JsonResponse
    {
        if ($result->success()) {
            return $this->renderWallet($result->wallet);
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Port of WalletActions#render_wallet — every single-wallet response
     * carries the recurring_transaction_rules / limitations /
     * applied_invoice_custom_sections includes.
     */
    private function renderWallet(Wallet $wallet): JsonResponse
    {
        return $this->renderSerializerJson((new WalletSerializer(
            $wallet,
            [
                'root_name' => 'wallet',
                'includes' => ['recurring_transaction_rules', 'limitations', 'applied_invoice_custom_sections'],
            ],
        ))->toJson());
    }

    private function renderWalletCollection(LengthAwarePaginator $wallets): JsonResponse
    {
        return $this->renderSerializerJson(
            (new CollectionSerializer(
                $wallets,
                WalletSerializer::class,
                [
                    'collection_name' => 'wallets',
                    'meta' => $this->paginationMetadata($wallets),
                    'includes' => ['recurring_transaction_rules', 'limitations', 'applied_invoice_custom_sections'],
                ],
            ))->toJson()
        );
    }

    /**
     * Port of the controller's private `customer` — resolved from the
     * wallet params' external_customer_id; nil when absent or unknown (the
     * create service then answers customer_not_found).
     */
    private function customer(Request $request): ?Customer
    {
        $externalCustomerId = $request->input('wallet.external_customer_id');

        $externalCustomerId = is_scalar($externalCustomerId) ? mb_trim((string) $externalCustomerId) : '';

        if ($externalCustomerId === '') {
            return null;
        }

        return Customer::query()
            ->where('external_id', $externalCustomerId)
            ->where('organization_id', $this->currentOrganization()->id)
            ->first();
    }

    /**
     * Port of WalletActions#input_params — the create contract, verbatim
     * (Rails' permitted params), merged with organization_id and customer
     * like `wallet_create` does.
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request, ?Customer $customer): array
    {
        /** @var mixed $wallet */
        $wallet = $this->requireParam($request, 'wallet');

        if (! is_array($wallet)) {
            throw new \App\Exceptions\Api\ParameterMissingException('wallet');
        }

        $params = $this->permitParams($wallet, [
            'rate_amount',
            'name',
            'code',
            'priority',
            'currency',
            'paid_credits',
            'granted_credits',
            'expiration_at',
            'invoice_requires_successful_payment',
            'paid_top_up_min_amount_cents',
            'paid_top_up_max_amount_cents',
            'ignore_paid_top_up_limits_on_creation',
            'purchase_order_number',
            'transaction_name',
            'transaction_priority',
            'billing_entity_code',
            'billing_entity_id',
            'metadata' => '*',
            'transaction_metadata' => [['key', 'value']],
            'recurring_transaction_rules' => [[
                'granted_credits',
                'grants_target_top_up',
                'interval',
                'method',
                'paid_credits',
                'started_at',
                'expiration_at',
                'target_ongoing_balance',
                'threshold_credits',
                'trigger',
                'invoice_requires_successful_payment',
                'ignore_paid_top_up_limits',
                'purchase_order_number',
                'transaction_name',
                'invoice_custom_section' => [
                    'skip_invoice_custom_sections',
                    'invoice_custom_section_codes' => [],
                ],
                'transaction_metadata' => [['key', 'value']],
                'payment_method' => [
                    'payment_method_type',
                    'payment_method_id',
                ],
                'connections' => [
                    'payment' => ['behavior', 'code'],
                    'tax' => ['behavior', 'code'],
                    'accounting' => ['behavior', 'code'],
                    'crm' => ['behavior', 'code'],
                ],
            ]],
            'applies_to' => [
                'fee_types' => [],
                'billable_metric_codes' => [],
            ],
            'invoice_custom_section' => [
                'skip_invoice_custom_sections',
                'invoice_custom_section_codes' => [],
            ],
            'payment_method' => [
                'payment_method_type',
                'payment_method_id',
            ],
            'connections' => [
                'payment' => ['behavior', 'code'],
                'tax' => ['behavior', 'code'],
                'accounting' => ['behavior', 'code'],
                'crm' => ['behavior', 'code'],
            ],
        ]);

        // Rails: params.merge(organization_id:, customer:).to_h.deep_symbolize_keys
        // — organization_id and customer are appended AFTER permitting (the
        // permit list never contains them, so they cannot be spoofed).
        $params['organization_id'] = $this->currentOrganization()->id;
        $params['customer'] = $customer;

        return $params;
    }

    /**
     * Port of WalletActions#update_params — the update contract, verbatim
     * (Rails' permitted params), with the id merged like `wallet_update`.
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request): array
    {
        /** @var mixed $wallet */
        $wallet = $this->requireParam($request, 'wallet');

        if (! is_array($wallet)) {
            throw new \App\Exceptions\Api\ParameterMissingException('wallet');
        }

        $params = $this->permitParams($wallet, [
            'name',
            'code',
            'priority',
            'expiration_at',
            'invoice_requires_successful_payment',
            'paid_top_up_min_amount_cents',
            'paid_top_up_max_amount_cents',
            'billing_entity_code',
            'purchase_order_number',
            'metadata' => '*',
            'recurring_transaction_rules' => [[
                'lago_id',
                'interval',
                'method',
                'started_at',
                'expiration_at',
                'target_ongoing_balance',
                'threshold_credits',
                'trigger',
                'paid_credits',
                'granted_credits',
                'grants_target_top_up',
                'invoice_requires_successful_payment',
                'ignore_paid_top_up_limits',
                'purchase_order_number',
                'transaction_name',
                'invoice_custom_section' => [
                    'skip_invoice_custom_sections',
                    'invoice_custom_section_codes' => [],
                ],
                'transaction_metadata' => [['key', 'value']],
                'payment_method' => [
                    'payment_method_type',
                    'payment_method_id',
                ],
                'connections' => [
                    'payment' => ['behavior', 'code'],
                    'tax' => ['behavior', 'code'],
                    'accounting' => ['behavior', 'code'],
                    'crm' => ['behavior', 'code'],
                ],
            ]],
            'applies_to' => [
                'fee_types' => [],
                'billable_metric_codes' => [],
            ],
            'invoice_custom_section' => [
                'skip_invoice_custom_sections',
                'invoice_custom_section_codes' => [],
            ],
            'payment_method' => [
                'payment_method_type',
                'payment_method_id',
            ],
            'connections' => [
                'payment' => ['behavior', 'code'],
                'tax' => ['behavior', 'code'],
                'accounting' => ['behavior', 'code'],
                'crm' => ['behavior', 'code'],
            ],
        ]);

        // Rails: update_params.merge(id: wallet&.id).
        $params['id'] = $request->route('id');

        return $params;
    }
}
