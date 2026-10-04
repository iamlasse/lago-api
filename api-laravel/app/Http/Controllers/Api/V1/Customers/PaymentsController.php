<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\V1\PaymentsController as BasePaymentsController;

/**
 * Port of Rails' Api::V1::Customers::PaymentsController — the customer-nested
 * payments index (GET /customers/:external_id/payments), reusing the
 * PaymentIndex behavior with the resolved customer's external_id.
 */
class PaymentsController extends BasePaymentsController
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->findCustomer($request);

        return $this->paymentIndex($request, externalCustomerId: $customer->external_id);
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
            throw new \App\Exceptions\Api\NotFoundException('customer');
        }

        return $customer;
    }
}
