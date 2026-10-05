<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\OrderForm;
use Illuminate\Http\Request;
use App\Queries\OrderFormsQuery;
use Illuminate\Http\JsonResponse;
use App\Services\OrderForms\VoidService;
use App\Exceptions\Api\NotFoundException;
use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;
use App\Serializers\V1\OrderFormSerializer;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Services\OrderForms\MarkAsSignedService;

/**
 * Port of Rails' Api::V1::OrderFormsController
 * (app/controllers/api/v1/order_forms_controller.rb) — index, show,
 * mark_as_signed and void, every action gated on the order_forms feature
 * flag (Rails: ensure_feature_flag! → 403 feature_unavailable).
 */
class OrderFormsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'order_form';

    public function index(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $result = OrderFormsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: $this->indexFilters($request),
            searchTerm: $request->query('search_term'),
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->order_forms,
                    OrderFormSerializer::class,
                    [
                        'collection_name' => 'order_forms',
                        'meta' => $this->paginationMetadata($result->order_forms),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $orderForm = $this->findOrderForm($request->route('id'));

        if ($orderForm === null) {
            throw new NotFoundException('order_form');
        }

        return $this->renderOrderForm($orderForm);
    }

    public function markAsSigned(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $orderForm = $this->findOrderForm($request->route('id'));

        $orderFormParams = $request->input('order_form');
        $orderFormParams = is_array($orderFormParams) ? $orderFormParams : [];

        $result = MarkAsSignedService::call(
            orderForm: $orderForm,
            signedDocument: $orderFormParams['signed_document'] ?? null,
            executionMode: $orderFormParams['execution_mode'] ?? null,
            executeAt: $orderFormParams['execute_at'] ?? null,
        );

        if ($result->success()) {
            return $this->renderOrderForm($result->order_form);
        }

        $this->renderErrorResponse($result);
    }

    public function void(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $orderForm = $this->findOrderForm($request->route('id'));

        $result = VoidService::call(orderForm: $orderForm);

        if ($result->success()) {
            return $this->renderOrderForm($result->order_form);
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Rails: ensure_feature_flag! — forbidden_error(code:
     * "feature_unavailable") unless the order_forms flag is on.
     */
    protected function ensureOrderFormsFeature(): void
    {
        $organization = $this->currentOrganization();

        if ($organization === null
            || ! in_array('order_forms', (array) ($organization->feature_flags ?? []), true)) {
            throw new ForbiddenException('feature_unavailable');
        }
    }

    /**
     * Rails: `index_filters` — the order forms index query params, verbatim.
     *
     * @return array<string, mixed>
     */
    protected function indexFilters(Request $request): array
    {
        return [
            'status' => $request->query('status'),
            'customer_id' => $request->query('customer_id'),
            'number' => $request->query('number'),
            'quote_number' => $request->query('quote_number'),
            'owner_id' => $request->query('owner_id'),
            'created_at_from' => $request->query('created_at_from'),
            'created_at_to' => $request->query('created_at_to'),
            'expires_at_from' => $request->query('expires_at_from'),
            'expires_at_to' => $request->query('expires_at_to'),
        ];
    }

    protected function findOrderForm(mixed $id): ?OrderForm
    {
        return OrderForm::query()
            ->where('organization_id', $this->currentOrganization()?->id)
            ->where('id', $id)
            ->first();
    }

    private function renderOrderForm(OrderForm $orderForm): JsonResponse
    {
        return $this->renderSerializerJson(
            (new OrderFormSerializer($orderForm, ['root_name' => 'order_form']))->toJson()
        );
    }
}
