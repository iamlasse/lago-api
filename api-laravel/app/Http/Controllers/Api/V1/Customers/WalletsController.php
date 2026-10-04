<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customers;

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
 * Port of Rails' Api::V1::Customers::WalletsController (app/controllers/
 * api/v1/customers/wallets_controller.rb) — the same WalletActions surface
 * as the top-level wallets controller, scoped to the customer resolved from
 * the route's :customer_external_id (Api::V1::Customers::BaseController's
 * before_action answers the customer not_found envelope for unknown ids).
 *
 * Wallets are keyed by CODE here (not id). Codes are only unique among
 * ACTIVE wallets, so a customer may own several wallets sharing a code —
 * `order(:status)` resolves to the active one first (Rails' find_wallet).
 *
 * Not ported (out of scope for this slice — dependencies do not exist yet):
 * the alerts and metadata subresources.
 */
class WalletsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'wallet';

    public function create(Request $request): JsonResponse
    {
        return $this->renderWalletAction(
            CreateService::call(params: $this->createParams($request)),
        );
    }

    public function update(Request $request): JsonResponse
    {
        $wallet = $this->findWallet($request);

        return $this->renderWalletAction(
            UpdateService::call(wallet: $wallet, params: $this->updateParams($request, $wallet)),
        );
    }

    public function terminate(Request $request): JsonResponse
    {
        return $this->renderWalletAction(
            TerminateService::call(wallet: $this->findWallet($request)),
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

        // Port of `params.permit(:currency, billing_entity_codes: [])` — the
        // external_customer_id always comes from the route.
        $currency = $request->query('currency');

        $result = WalletsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: array_filter([
                'external_customer_id' => $this->requireCustomer($request)->external_id,
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

    /**
     * Port of Customers::BaseController#find_customer — an unknown
     * :customer_external_id answers the not_found envelope before any
     * action runs in Rails; here the actions resolve it first.
     */
    private function customer(Request $request): ?Customer
    {
        $externalId = $request->route('external_id');

        return Customer::query()
            ->where('external_id', is_scalar($externalId) ? (string) $externalId : '')
            ->where('organization_id', $this->currentOrganization()->id)
            ->first();
    }

    private function requireCustomer(Request $request): Customer
    {
        $customer = $this->customer($request);

        if ($customer === null) {
            throw new NotFoundException('customer');
        }

        return $customer;
    }

    /**
     * Port of `find_wallet`: `customer.wallets.order(:status).find_by(
     * code: params[:code])` — ordering by status resolves to the ACTIVE
     * wallet first when several share the code.
     */
    private function findWallet(Request $request): ?Wallet
    {
        return $this->requireCustomer($request)
            ->wallets()
            ->orderBy('status')
            ->where('code', $request->route('code'))
            ->first();
    }

    /**
     * Port of WalletActions#wallet_create — the customer comes from the
     * ROUTE (never from the params' external_customer_id, which is not even
     * consulted here).
     */
    private function renderWalletAction(\App\Services\BaseResult $result): JsonResponse
    {
        if ($result->success()) {
            return $this->renderWallet($result->wallet);
        }

        $this->renderErrorResponse($result);
    }

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
     * Port of WalletActions#input_params (create contract, verbatim), with
     * the ROUTE customer merged in (Rails: `.merge(customer:)`).
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        /** @var mixed $wallet */
        $wallet = $this->requireParam($request, 'wallet');

        if (! is_array($wallet)) {
            throw new \App\Exceptions\Api\ParameterMissingException('wallet');
        }

        $params = $this->createContract($wallet);

        $params['organization_id'] = $this->currentOrganization()->id;
        $params['customer'] = $this->requireCustomer($request);

        return $params;
    }

    /**
     * Port of WalletActions#update_params (update contract, verbatim), with
     * the id merged like `wallet_update`.
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request, ?Wallet $wallet): array
    {
        /** @var mixed $walletParam */
        $walletParam = $this->requireParam($request, 'wallet');

        if (! is_array($walletParam)) {
            throw new \App\Exceptions\Api\ParameterMissingException('wallet');
        }

        $params = $this->updateContract($walletParam);

        // Rails: update_params.merge(id: wallet&.id).
        $params['id'] = $wallet?->id;

        return $params;
    }

    /** Port of the create permit list. */
    private function createContract(array $wallet): array
    {
        return $this->permitParams($wallet, [
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
    }

    /** Port of the update permit list. */
    private function updateContract(array $wallet): array
    {
        return $this->permitParams($wallet, [
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
    }
}
