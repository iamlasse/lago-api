<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Queries\PaymentMethodsQuery;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\PaymentMethodSerializer;
use App\Services\PaymentMethods\DestroyService;
use App\Services\PaymentMethods\SetAsDefaultService;

/**
 * Port of Rails' Api::V1::Customers::PaymentMethodsController
 * (app/controllers/api/v1/customers/payment_methods_controller.rb):
 * GET index (PaymentMethodIndex concern), DELETE destroy (discard) and
 * PUT set_as_default — all scoped to the customer resolved from the route.
 */
class PaymentMethodsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'payment_method';

    public function index(Request $request): JsonResponse
    {
        $customer = $this->findCustomer($request);

        $result = PaymentMethodsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: [
                'external_customer_id' => $customer->external_id,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->payment_methods,
                    PaymentMethodSerializer::class,
                    [
                        'collection_name' => 'payment_methods',
                        'meta' => $this->paginationMetadata($result->payment_methods),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $customer = $this->findCustomer($request);

        $paymentMethod = $customer->paymentMethods()
            ->where('payment_methods.id', $request->route('id'))
            ->first();

        $result = DestroyService::call(paymentMethod: $paymentMethod);

        if ($result->success()) {
            return $this->renderSerializerJson($this->toJson($result->payment_method));
        }

        $this->renderErrorResponse($result);
    }

    public function setAsDefault(Request $request): JsonResponse
    {
        $customer = $this->findCustomer($request);

        $paymentMethod = $customer->paymentMethods()
            ->where('payment_methods.id', $request->route('id'))
            ->first();

        if ($paymentMethod === null) {
            throw new NotFoundException('payment_method');
        }

        $result = SetAsDefaultService::call(paymentMethod: $paymentMethod);

        if ($result->success()) {
            return $this->renderSerializerJson($this->toJson($result->payment_method));
        }

        $this->renderErrorResponse($result);
    }

    private function toJson(\App\Models\PaymentMethod $paymentMethod): string
    {
        return (new PaymentMethodSerializer($paymentMethod, ['root_name' => 'payment_method']))->toJson();
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
