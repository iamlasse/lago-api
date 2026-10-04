<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use App\Queries\WalletTransactionsQuery;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\WalletTransactionSerializer;
use App\Services\WalletTransactions\CreateFromParamsService;

/**
 * Port of Rails' Api::V1::WalletTransactionsController (app/controllers/
 * api/v1/wallet_transactions_controller.rb). The index lives at
 * GET /wallets/:id/wallet_transactions (Rails' standalone draw), create at
 * POST /wallet_transactions and show at GET /wallet_transactions/:id.
 *
 * Not registered (dependencies do not exist yet): payment_url
 * (WalletTransactions::Payments::GeneratePaymentUrlService — the payment
 * providers slice), consumptions / fundings
 * (WalletTransactionConsumptionsQuery + consumption serializers).
 */
class WalletTransactionsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'wallet_transaction';

    public function create(Request $request): JsonResponse
    {
        $result = CreateFromParamsService::call(
            organization: $this->currentOrganization(),
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->wallet_transactions,
                    WalletTransactionSerializer::class,
                    ['collection_name' => 'wallet_transactions'],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function index(Request $request): JsonResponse
    {
        $result = WalletTransactionsQuery::call(
            organization: $this->currentOrganization(),
            walletId: $request->route('id'),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: [
                'status' => $this->scalarQuery($request, 'status'),
                'transaction_type' => $this->scalarQuery($request, 'transaction_type'),
                'transaction_status' => $this->scalarQuery($request, 'transaction_status'),
                // Only a nested hash param (?metadata[key]=value) filters;
                // scalar/array shapes are ignored (Rails:
                // params[:metadata].respond_to?(:permit!)).
                'metadata' => $this->hashQuery($request, 'metadata'),
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->wallet_transactions,
                    WalletTransactionSerializer::class,
                    [
                        'collection_name' => 'wallet_transactions',
                        'meta' => $this->paginationMetadata($result->wallet_transactions),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $walletTransaction = WalletTransaction::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('id', $request->route('id'))
            ->first();

        if ($walletTransaction === null) {
            throw new NotFoundException('wallet_transaction');
        }

        return $this->renderSerializerJson((new WalletTransactionSerializer(
            $walletTransaction,
            [
                'root_name' => 'wallet_transaction',
                'includes' => ['applied_invoice_custom_sections'],
            ],
        ))->toJson());
    }

    // -- Helpers ---------------------------------------------------------------------

    /** A scalar (or absent) query value — arrays are dropped, like `permit`. */
    private function scalarQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * A nested hash query value (`?metadata[key]=value`); any other shape
     * yields null.
     *
     * @return array<string, mixed>|null
     */
    private function hashQuery(Request $request, string $key): ?array
    {
        $value = $request->query($key);

        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    /**
     * Port of `params.require(:wallet_transaction).permit(...)` — the
     * create contract, verbatim (Rails' permitted params).
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $walletTransaction */
        $walletTransaction = $this->requireParam($request, 'wallet_transaction');

        if (! is_array($walletTransaction)) {
            throw new \App\Exceptions\Api\ParameterMissingException('wallet_transaction');
        }

        return $this->permitParams($walletTransaction, [
            'wallet_id',
            'paid_credits',
            'granted_credits',
            'voided_credits',
            'voided_transaction_id',
            'invoice_requires_successful_payment',
            'name',
            'purchase_order_number',
            'ignore_paid_top_up_limits',
            'priority',
            'payment_method' => [
                'payment_method_type',
                'payment_method_id',
            ],
            'metadata' => [['key', 'value']],
            'invoice_custom_section' => [
                'skip_invoice_custom_sections',
                'invoice_custom_section_codes' => [],
            ],
        ]);
    }
}
