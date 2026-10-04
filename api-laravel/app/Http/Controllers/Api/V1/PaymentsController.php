<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use Illuminate\Http\Request;
use App\Queries\PaymentsQuery;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Serializers\V1\PaymentSerializer;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Services\Payments\ManualCreateService;

/**
 * Port of Rails' Api::V1::PaymentsController
 * (app/controllers/api/v1/payments_controller.rb): POST /payments records a
 * manual payment (Payments::ManualCreateService), GET /payments lists with
 * invoice_id / external_customer_id filters, GET /payments/:id shows one
 * (scoped through the for_organization visible-payable rule).
 */
class PaymentsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'payment';

    public function create(Request $request): JsonResponse
    {
        $result = ManualCreateService::call(
            organization: $this->currentOrganization(),
            params: $this->createParams($request),
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new PaymentSerializer($result->payment, ['root_name' => 'payment']))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->paymentIndex($request, externalCustomerId: $this->scalarQuery($request, 'external_customer_id'));
    }

    public function show(Request $request): JsonResponse
    {
        $payment = Payment::forOrganization($this->currentOrganization())
            ->where('payments.id', $request->route('id'))
            ->first();

        if ($payment === null) {
            throw new NotFoundException('payment');
        }

        return $this->renderSerializerJson(
            (new PaymentSerializer($payment, ['root_name' => 'payment']))->toJson()
        );
    }

    /**
     * Port of the PaymentIndex concern — shared by the top-level and the
     * customer-nested controllers.
     */
    protected function paymentIndex(Request $request, ?string $externalCustomerId): JsonResponse
    {
        $result = PaymentsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: [
                'invoice_id' => $this->scalarQuery($request, 'invoice_id'),
                'external_customer_id' => $externalCustomerId,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->payments,
                    PaymentSerializer::class,
                    [
                        'collection_name' => 'payments',
                        'meta' => $this->paginationMetadata($result->payments),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    /** A scalar (or absent) query value — arrays are dropped, like `permit`. */
    protected function scalarQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Port of `params.require(:payment).permit(:invoice_id, :amount_cents,
     * :reference, :paid_at)`.
     *
     * @return array<string, mixed>
     */
    protected function createParams(Request $request): array
    {
        /** @var mixed $payment */
        $payment = $this->requireParam($request, 'payment');

        if (! is_array($payment)) {
            throw new \App\Exceptions\Api\ParameterMissingException('payment');
        }

        return $this->permitParams($payment, ['invoice_id', 'amount_cents', 'reference', 'paid_at']);

        // Rails: `deep_symbolize_keys` then ManualCreateService checks
        // amount_cents is an Integer — JSON numerics arrive as int/float;
        // float values are rejected downstream (invalid_value).
    }
}
