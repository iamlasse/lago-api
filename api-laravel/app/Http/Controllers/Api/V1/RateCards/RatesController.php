<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\RateCards;

use App\Models\RateCard;
use App\Models\RateCardRate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Queries\RateCardRatesQuery;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Services\RateCardRates\CreateService;
use App\Services\RateCardRates\UpdateService;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\RateCardRateSerializer;
use App\Services\RateCardRates\DestroyService;
use App\Http\Controllers\Concerns\RequiresProductCatalog;

/**
 * Port of Rails' Api::V2::RateCards::RatesController
 * (app/controllers/api/v2/rate_cards/rates_controller.rb).
 */
class RatesController extends ApiController
{
    use Pagination;
    use RequiresProductCatalog;

    protected ?string $resourceName = 'rate_card';

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $rateCard = $this->findRateCard($request);

        $result = RateCardRatesQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: ['rate_card_id' => $rateCard->id],
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->rate_card_rates,
                RateCardRateSerializer::class,
                [
                    'collection_name' => 'rates',
                    'meta' => $this->paginationMetadata($result->rate_card_rates),
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $this->findRateCard($request);

        return $this->renderRate($this->findRate($request));
    }

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $rateCard = $this->findRateCard($request);

        $result = CreateService::call(
            rateCard: $rateCard,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            return $this->renderRate($result->rate_card_rate);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $this->findRateCard($request);
        $rate = $this->findRate($request);

        $result = UpdateService::call(
            rateCardRate: $rate,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            return $this->renderRate($result->rate_card_rate);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();
        $this->findRateCard($request);
        $rate = $this->findRate($request);

        $result = DestroyService::call(rateCardRate: $rate);

        if ($result->success()) {
            return $this->renderRate($result->rate_card_rate);
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function findRateCard(Request $request): RateCard
    {
        $rateCard = $this->currentOrganization()->rateCards()
            ->where('code', $request->route('rate_card_code'))
            ->first();

        if ($rateCard === null) {
            throw new \App\Exceptions\Api\NotFoundException('rate_card');
        }

        return $rateCard;
    }

    private function findRate(Request $request): RateCardRate
    {
        $rate = RateCardRate::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('rate_card_id', $this->currentOrganization()->rateCards()->where('code', $request->route('rate_card_code'))->value('id') ?? '')
            ->where('code', $request->route('code'))
            ->first();

        if ($rate === null) {
            throw new \App\Exceptions\Api\NotFoundException('rate_card_rate');
        }

        return $rate;
    }

    private function renderRate(RateCardRate $rate): JsonResponse
    {
        return $this->renderSerializerJson((new RateCardRateSerializer($rate, ['root_name' => 'rate']))->toJson());
    }

    /**
     * Port of `params.require(:rate).permit(..., rate_properties: {})`.
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        $rate = $this->requireParams($request, 'rate');

        return $this->permitParams($rate, [
            'code',
            'effective_from',
            'rate_model',
            'min_amount_cents',
            'billing_interval_count',
            'billing_interval_unit',
            'applied_pricing_unit_conversion_rate',
            'rate_properties' => '*',
        ]);
    }
}
