<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Queries\PaymentReceiptsQuery;
use App\Exceptions\Api\NotFoundException;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\PaymentReceiptSerializer;
use App\Http\Controllers\Api\V1\PaymentReceiptsController as BasePaymentReceiptsController;

/**
 * Port of the customer-nested payment receipts index
 * (GET /customers/:external_id/payment_receipts — the customers-nested
 * subresource served from the receipts slice): the receipts of the
 * organization, filtered down to the resolved customer's payments through
 * the PaymentReceiptsQuery invoice/customer filter.
 */
class PaymentReceiptsController extends BasePaymentReceiptsController
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->findCustomer($request);

        $result = PaymentReceiptsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: [
                'invoice_id' => $this->scalarQuery($request, 'invoice_id'),
                'external_customer_id' => $customer->external_id,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->payment_receipts,
                    PaymentReceiptSerializer::class,
                    [
                        'collection_name' => 'payment_receipts',
                        'meta' => $this->paginationMetadata($result->payment_receipts),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Port of Customers::BaseController#find_customer — the customer must
     * belong to the current organization, else the not_found envelope.
     */
    private function findCustomer(Request $request): Customer
    {
        $customer = Customer::query()
            ->where('external_id', $request->route('external_id'))
            ->where('organization_id', $this->currentOrganization()->id)
            ->first();

        if ($customer === null) {
            throw new NotFoundException('customer');
        }

        return $customer;
    }
}
