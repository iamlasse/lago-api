<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Event;
use App\Queries\EventsQuery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Events\CreateService;
use App\Serializers\V1\EventSerializer;
use App\Exceptions\Api\NotFoundException;
use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;
use App\Services\Events\CreateBatchService;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;

/**
 * Port of Rails' Api::V1::EventsController
 * (app/controllers/api/v1/events_controller.rb) — skip_audit_logs! and the
 * resource_name ("event") are honored via the shared ApiController surface.
 *
 * Not ported (dependencies do not exist):
 * - TODO(port): estimate_fees / estimate_instant_fees /
 *   batch_estimate_instant_fees (Fees::EstimateInstant::PayInAdvanceService
 *   family — the pay-in-advance metering slice); their ledger rows land
 *   with that slice.
 * - TODO(port): the ClickHouse branches of show/index (Clickhouse::EventsRaw)
 *   and the enriched serializer of index_enriched — the port always reads
 *   the Postgres `events` table, Rails' pg path.
 * - TODO(port): kafka raw-events producer (Events::KafkaProducerService —
 *   produced by the create services, M2 later).
 */
class EventsController extends ApiController
{
    use Pagination;

    /** Rails: ACTIONS_WITH_CACHED_API_KEY. */
    private const ACTIONS_WITH_CACHED_API_KEY = ['create', 'batch', 'estimate_instant_fees', 'batch_estimate_instant_fees'];

    protected ?string $resourceName = 'event';

    public function create(Request $request): JsonResponse
    {
        $result = CreateService::call(
            organization: $this->currentOrganization(),
            params: $this->createParams($request),
            timestamp: microtime(true),
            metadata: $this->eventMetadata($request),
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new EventSerializer(
                $result->event,
                ['root_name' => 'event'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function batch(Request $request): JsonResponse
    {
        $result = CreateBatchService::call(
            organization: $this->currentOrganization(),
            eventsParams: $this->batchParams($request),
            timestamp: microtime(true),
            metadata: $this->eventMetadata($request),
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->events,
                EventSerializer::class,
                ['collection_name' => 'events'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        // TODO(port): Clickhouse::EventsRaw when the org uses the clickhouse
        // store — Rails resolves the model by store; the pg path is served.
        $event = Event::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('transaction_id', $request->route('id'))
            ->first();

        if ($event === null) {
            throw new NotFoundException('event');
        }

        return $this->renderSerializerJson((new EventSerializer(
            $event,
            ['root_name' => 'event'],
        ))->toJson());
    }

    public function index(Request $request): JsonResponse
    {
        $result = EventsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->input('page'),
                'limit' => $request->input('per_page') ?? $this->perPage(),
            ],
            filters: $this->indexFilters($request),
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->events,
                EventSerializer::class,
                [
                    'collection_name' => 'events',
                    'meta' => $this->paginationMetadata($result->events),
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Rails: index_enriched — `before_action :ensure_organization_uses_clickhouse`
     * answers 403 endpoint_not_available for every non-clickhouse org, which
     * is the only reachable path in this environment (TODO(port): the
     * Clickhouse::EventsEnriched query + V1::EventEnrichedSerializer).
     */
    public function indexEnriched(Request $request): JsonResponse
    {
        if (! $this->currentOrganization()->clickhouseEventsStore()) {
            throw new ForbiddenException('endpoint_not_available');
        }

        $result = EventsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->input('page'),
                'limit' => $request->input('per_page') ?? $this->perPage(),
            ],
            filters: array_merge($this->indexFilters($request), ['enriched' => true]),
        );

        if ($result->success()) {
            // Rails: set_beta_header! — the beta status header is set on this
            // action even on the v1 mount.
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->events,
                EventSerializer::class,
                [
                    'collection_name' => 'events',
                    'meta' => $this->paginationMetadata($result->events),
                ],
            ))->toJson())->header('X-Lago-Endpoint-Status', 'beta');
        }

        $this->renderErrorResponse($result);
    }

    /** Rails: `track_api_key_usage?` — every action but create. */
    public function trackApiKeyUsage(): bool
    {
        return request()->route()?->getActionMethod() !== 'create';
    }

    /** Rails: `cached_api_key?` — create/batch/estimate actions. */
    public function cachedApiKey(): bool
    {
        return in_array(request()->route()?->getActionMethod(), self::ACTIONS_WITH_CACHED_API_KEY, true);
    }

    // -- Params ---------------------------------------------------------------

    /**
     * Rails: create_params — `params.require(:event).permit(...)`.
     * external_contract_id is the v2 alias permitted here so it reaches the
     * create service.
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        $event = $this->requireParam($request, 'event');

        return $this->permitParams((array) $event, [
            'transaction_id',
            'code',
            'timestamp',
            'external_subscription_id',
            'external_contract_id',
            'precise_total_amount_cents',
            'properties' => '*',
        ]);
    }

    /**
     * Rails: batch_params — `params.permit(events: [...])`, deep-symbolized.
     *
     * @return array<string, mixed>
     */
    private function batchParams(Request $request): array
    {
        $permitted = $this->permitParams((array) $request->all(), [
            'events' => [[
                'transaction_id',
                'code',
                'timestamp',
                'external_subscription_id',
                'external_contract_id',
                'precise_total_amount_cents',
                'properties' => '*',
            ]],
        ]);

        $events = array_map(
            fn (array $event): array => $event,
            (array) ($permitted['events'] ?? []),
        );

        return ['events' => $events];
    }

    /**
     * @return array<string, mixed>
     */
    private function indexFilters(Request $request): array
    {
        return array_filter(
            $request->only(['code', 'external_subscription_id', 'timestamp_from_started_at', 'timestamp_from', 'timestamp_to']),
            fn ($value): bool => $value !== null,
        );
    }

    /**
     * Rails: event_metadata — the user agent and remote ip ride along on the
     * stored event.
     *
     * @return array{user_agent: ?string, ip_address: ?string}
     */
    private function eventMetadata(Request $request): array
    {
        return [
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ];
    }

    private function perPage(): int
    {
        return self::PER_PAGE;
    }
}
