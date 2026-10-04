<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Coupon;
use Illuminate\Http\Request;
use App\Queries\CouponsQuery;
use Illuminate\Http\JsonResponse;
use App\Services\Coupons\CreateService;
use App\Services\Coupons\UpdateService;
use App\Serializers\V1\CouponSerializer;
use App\Services\Coupons\DestroyService;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Exceptions\Api\ParameterMissingException;

/**
 * Port of Rails' Api::V1::CouponsController
 * (app/controllers/api/v1/coupons_controller.rb) — a coupon is keyed by its
 * code (Rails: resources :coupons, param: :code).
 */
class CouponsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'coupon';

    public function create(Request $request): JsonResponse
    {
        $result = CreateService::call(
            args: array_merge($this->inputParams($request), [
                'organization_id' => $this->currentOrganization()->id,
            ]),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderCoupon($result->coupon);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $coupon = $this->currentOrganizationCouponQuery($request);

        $result = UpdateService::call(
            coupon: $coupon,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderCoupon($result->coupon);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $coupon = $this->currentOrganizationCouponQuery($request);

        $result = DestroyService::call(coupon: $coupon);

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderCoupon($result->coupon);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $coupon = $this->currentOrganizationCouponQuery($request);

        if ($coupon === null) {
            throw new NotFoundException('coupon');
        }

        return $this->renderCoupon($coupon);
    }

    public function index(Request $request): JsonResponse
    {
        $result = CouponsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->coupons,
                    CouponSerializer::class,
                    [
                        'collection_name' => 'coupons',
                        'meta' => $this->paginationMetadata($result->coupons),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Rails: current_organization.coupons.find_by(code: params[:code]).
     */
    private function currentOrganizationCouponQuery(Request $request): ?Coupon
    {
        return Coupon::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('code', $request->route('code'))
            ->first();
    }

    private function renderCoupon(Coupon $coupon): JsonResponse
    {
        return $this->renderSerializerJson(
            (new CouponSerializer($coupon, ['root_name' => 'coupon']))->toJson()
        );
    }

    /**
     * Port of `params.require(:coupon).permit(...)` — the contract, verbatim
     * (Rails' permitted params).
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $coupon */
        $coupon = $this->requireParam($request, 'coupon');

        if (! is_array($coupon)) {
            throw new ParameterMissingException('coupon');
        }

        return $this->permitParams($coupon, [
            'name',
            'code',
            'description',
            'coupon_type',
            'amount_cents',
            'amount_currency',
            'percentage_rate',
            'frequency',
            'frequency_duration',
            'expiration',
            'expiration_at',
            'reusable',
            'applies_to' => [
                'plan_codes' => [],
                'billable_metric_codes' => [],
            ],
        ]);
    }
}
