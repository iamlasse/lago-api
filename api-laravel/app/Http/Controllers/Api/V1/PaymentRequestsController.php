<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\PaymentRequest;
use Illuminate\Http\JsonResponse;
use App\Queries\PaymentRequestsQuery;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Services\PaymentRequests\CreateService;
use App\Serializers\V1\PaymentRequestSerializer;

/**
 * Port of Rails' Api::V1::PaymentRequestsController
 * (app/controllers/api/v1/payment_requests_controller.rb): POST creates a
 * dunning payment request (premium), GET indexes with payment_status /
 * currency / billing_entity_codes filters, GET /:id shows one.
 */
class PaymentRequestsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'payment_request';

    public function create(Request $request): JsonResponse
    {
        $result = CreateService::call(
            organization: $this->currentOrganization(),
            params: $this->createParams($request),
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new PaymentRequestSerializer($result->payment_request, [
                    'root_name' => 'payment_request',
                    'includes' => ['customer', 'invoices'],
                ]))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->paymentRequestIndex($request, externalCustomerId: $this->scalarQuery($request, 'external_customer_id'));
    }

    public function show(Request $request): JsonResponse
    {
        $paymentRequest = PaymentRequest::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('id', $request->route('id'))
            ->first();

        if ($paymentRequest === null) {
            throw new NotFoundException('payment_request');
        }

        $paymentRequest->load(['customer', 'invoices']);

        return $this->renderSerializerJson(
            (new PaymentRequestSerializer($paymentRequest, [
                'root_name' => 'payment_request',
                'includes' => ['customer', 'invoices'],
            ]))->toJson()
        );
    }

    /**
     * Port of the PaymentRequestIndex concern — shared with the
     * customer-nested controller. Unknown billing_entity_codes answer the
     * billing_entity not_found envelope.
     */
    protected function paymentRequestIndex(Request $request, ?string $externalCustomerId): JsonResponse
    {
        $filters = [
            'payment_status' => $this->scalarQuery($request, 'payment_status'),
            'currency' => $this->scalarQuery($request, 'currency'),
            'external_customer_id' => $externalCustomerId,
        ];

        $billingEntityCodes = $request->query('billing_entity_codes');

        if (is_array($billingEntityCodes)) {
            $codes = array_values(array_filter($billingEntityCodes, fn ($c): bool => is_scalar($c)));
            $billingEntities = $this->currentOrganization()->allBillingEntities()
                ->whereIn('code', $codes)
                ->get();

            if ($billingEntities->count() !== count(array_unique($codes))) {
                throw new NotFoundException('billing_entity');
            }

            $filters['billing_entity_ids'] = $billingEntities->pluck('id')->all();
        }

        $result = PaymentRequestsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: $filters,
        );

        if ($result->success()) {
            $result->payment_requests->load(['customer', 'invoices']);

            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->payment_requests,
                    PaymentRequestSerializer::class,
                    [
                        'collection_name' => 'payment_requests',
                        'meta' => $this->paginationMetadata($result->payment_requests),
                        'includes' => ['customer', 'invoices'],
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
     * Port of `params.require(:payment_request).permit(:email,
     * :external_customer_id, lago_invoice_ids: [], payment_method:
     * [:payment_method_type, :payment_method_id])`.
     *
     * @return array<string, mixed>
     */
    protected function createParams(Request $request): array
    {
        /** @var mixed $paymentRequest */
        $paymentRequest = $this->requireParam($request, 'payment_request');

        if (! is_array($paymentRequest)) {
            throw new \App\Exceptions\Api\ParameterMissingException('payment_request');
        }

        return $this->permitParams($paymentRequest, [
            'email',
            'external_customer_id',
            'lago_invoice_ids' => [],
            'payment_method' => [
                'payment_method_type',
                'payment_method_id',
            ],
        ]);
    }
}
