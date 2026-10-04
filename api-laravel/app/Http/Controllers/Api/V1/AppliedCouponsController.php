<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Coupon;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Queries\AppliedCouponsQuery;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Services\AppliedCoupons\CreateService;
use App\Serializers\V1\AppliedCouponSerializer;
use App\Exceptions\Api\ParameterMissingException;

/**
 * Port of Rails' Api::V1::AppliedCouponsController
 * (app/controllers/api/v1/applied_coupons_controller.rb) + the
 * AppliedCouponIndex concern (app/controllers/concerns/
 * applied_coupon_index.rb).
 */
class AppliedCouponsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'applied_coupon';

    public function create(Request $request): JsonResponse
    {
        $createParams = $this->createParams($request);

        // Rails: Customer.find_by(external_id:, organization_id:) /
        // Coupon.find_by(code:, organization_id:) — both reach the service
        // as nil when unknown (the service answers the not_found envelope).
        $customer = Customer::query()
            ->where('external_id', $createParams['external_customer_id'] ?? null)
            ->where('organization_id', $this->currentOrganization()->id)
            ->first();

        $coupon = Coupon::query()
            ->where('code', $createParams['coupon_code'] ?? null)
            ->where('organization_id', $this->currentOrganization()->id)
            ->first();

        $result = CreateService::call(customer: $customer, coupon: $coupon, params: $createParams);

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
     * Rails: index passes the external_customer_id QUERY param through (an
     * optional filter).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var mixed $externalCustomerId */
        $externalCustomerId = $request->query('external_customer_id');

        return $this->appliedCouponIndex(is_string($externalCustomerId) ? $externalCustomerId : null, $request);
    }

    /**
     * Port of AppliedCouponIndex#applied_coupon_index — the nested
     * customers/:external_id/applied_coupons controller reuses it the way
     * Rails' controllers `include AppliedCouponIndex`.
     */
    protected function appliedCouponIndex(?string $externalCustomerId, Request $request): JsonResponse
    {
        // Rails: params.permit(:status, coupon_code: [])
        $filters = [
            'status' => $request->query('status'),
            'coupon_code' => $request->query('coupon_code'),
            'external_customer_id' => $externalCustomerId,
        ];

        $result = AppliedCouponsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: $filters,
        );

        if ($result->success()) {
            // Rails: result.applied_coupons.includes(:credits, :coupon, :customer)
            $result->applied_coupons->load(['credits', 'coupon', 'customer']);

            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->applied_coupons,
                    AppliedCouponSerializer::class,
                    [
                        'collection_name' => 'applied_coupons',
                        'meta' => $this->paginationMetadata($result->applied_coupons),
                        'includes' => ['credits'],
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Port of `params.require(:applied_coupon).permit(...)` — the contract,
     * verbatim (Rails' permitted params).
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        /** @var mixed $appliedCoupon */
        $appliedCoupon = $this->requireParam($request, 'applied_coupon');

        if (! is_array($appliedCoupon)) {
            throw new ParameterMissingException('applied_coupon');
        }

        return $this->permitParams($appliedCoupon, [
            'external_customer_id',
            'coupon_code',
            'frequency',
            'frequency_duration',
            'amount_cents',
            'amount_currency',
            'percentage_rate',
        ]);
    }
}
