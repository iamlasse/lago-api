<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Queries\WebhookEndpointsQuery;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Exceptions\Api\ParameterMissingException;
use App\Serializers\V1\WebhookEndpointSerializer;
use App\Services\Webhooks\Endpoints\CreateService;
use App\Services\Webhooks\Endpoints\UpdateService;
use App\Services\Webhooks\Endpoints\DestroyService;

/**
 * Port of Rails' Api::V1::WebhookEndpointsController
 * (app/controllers/api/v1/webhook_endpoints_controller.rb) — an endpoint
 * is keyed by its uuid id (Rails: resources :webhook_endpoints).
 */
class WebhookEndpointsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'webhook_endpoint';

    public function create(Request $request): JsonResponse
    {
        $result = CreateService::call(
            organization: $this->currentOrganization(),
            params: $this->webhookEndpointParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderWebhookEndpoint($result->webhook_endpoint);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $result = UpdateService::call(
            id: (string) $request->route('id'),
            organization: $this->currentOrganization(),
            params: $this->updateParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderWebhookEndpoint($result->webhook_endpoint);
        }

        $this->renderErrorResponse($result);
    }

    public function index(Request $request): JsonResponse
    {
        $result = WebhookEndpointsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->webhook_endpoints,
                    WebhookEndpointSerializer::class,
                    [
                        'collection_name' => 'webhook_endpoints',
                        'meta' => $this->paginationMetadata($result->webhook_endpoints),
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $webhookEndpoint = $this->currentOrganization()
            ->webhookEndpoints()
            ->where('id', $request->route('id'))
            ->first();

        if ($webhookEndpoint === null) {
            throw new NotFoundException('webhook_endpoint');
        }

        return $this->renderWebhookEndpoint($webhookEndpoint);
    }

    public function destroy(Request $request): JsonResponse
    {
        $webhookEndpoint = $this->currentOrganization()
            ->webhookEndpoints()
            ->where('id', $request->route('id'))
            ->first();

        $result = DestroyService::call(webhookEndpoint: $webhookEndpoint);

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderWebhookEndpoint($result->webhook_endpoint);
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Port of `render_webhook_endpoint`.
     */
    private function renderWebhookEndpoint(object $webhookEndpoint): JsonResponse
    {
        return $this->renderSerializerJson((new WebhookEndpointSerializer(
            $webhookEndpoint,
            ['root_name' => 'webhook_endpoint'],
        ))->toJson());
    }

    /**
     * Port of `update_params` — the create contract minus the id.
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request): array
    {
        return Arr::except($this->webhookEndpointParams($request), ['id']);
    }

    /**
     * Port of `webhook_endpoint_params` — `params.require(:webhook_endpoint)
     * .permit(:id, :webhook_url, :signature_algo, :name, event_types: [])`,
     * preserving a non-array event_types value the array filter dropped
     * (the service's validation reports it).
     *
     * @return array<string, mixed>
     */
    private function webhookEndpointParams(Request $request): array
    {
        /** @var mixed $raw */
        $raw = $this->requireParam($request, 'webhook_endpoint');

        if (! is_array($raw)) {
            throw new ParameterMissingException('webhook_endpoint');
        }

        $permitted = $this->permitParams($raw, [
            'id',
            'webhook_url',
            'signature_algo',
            'name',
            'event_types' => [],
        ]);

        // preserve event_types non-array value if it was explicitly provided
        // invalid values will be handled in the service validation
        if (array_key_exists('event_types', $raw) && ! array_key_exists('event_types', $permitted)) {
            $permitted['event_types'] = $raw['event_types'];
        }

        return $permitted;
    }
}
