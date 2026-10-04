<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customers;

use App\Models\Customer;
use Illuminate\Http\Request;
use App\Models\AppliedCoupon;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Serializers\V1\AppliedCouponSerializer;
use App\Services\AppliedCoupons\TerminateService;
use App\Http\Controllers\Api\V1\AppliedCouponsController as BaseAppliedCouponsController;

/**
 * Port of Rails' Api::V1::Customers::AppliedCouponsController
 * (app/controllers/api/v1/customers/applied_coupons_controller.rb) — nested
 * under customers/:external_id, where the base controller resolves the
 * customer from the organization (404 envelope when unknown).
 *
 * The index behavior comes from the AppliedCouponIndex concern; extending
 * the top-level AppliedCouponsController is the port of that include.
 */
class AppliedCouponsController extends BaseAppliedCouponsController
{
    /**
     * Rails: applied_coupon_index(external_customer_id: customer.external_id)
     * — the customer was resolved from params[:external_id] first (Rails'
     * Customers::BaseController#find_customer).
     */
    public function index(Request $request): JsonResponse
    {
        $customer = $this->findCustomer($request);

        return $this->appliedCouponIndex($customer->external_id, $request);
    }

    public function destroy(Request $request): JsonResponse
    {
        $customer = $this->findCustomer($request);

        // Rails: customer.applied_coupons.find_by(id: params[:id]).
        $appliedCoupon = AppliedCoupon::query()
            ->where('customer_id', $customer->id)
            ->where('id', $request->route('id'))
            ->first();

        if ($appliedCoupon === null) {
            throw new NotFoundException('applied_coupon');
        }

        $result = TerminateService::call(appliedCoupon: $appliedCoupon);

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderSerializerJson(
                (new AppliedCouponSerializer(
                    $result->applied_coupon,
                    ['root_name' => 'applied_coupon'],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Port of Customers::BaseController#find_customer — the customer must
     * belong to the CURRENT organization, else the not_found envelope.
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
