<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\SendHttpWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookEndpoint;
use App\Services\BaseResult;
use App\Services\BaseService as RootBaseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Port of Rails' Webhooks::BaseService
 * (app/services/webhooks/base_service.rb) — the webhook fan-out.
 *
 * NOTE: Abstract Service, should not be used directly.
 *
 * The payload is persisted once per subscribed webhook_endpoint (status
 * pending) and a SendHttpWebhookJob is enqueued for each. Rails' TODO about
 * wrapping in a transaction still holds: each row commits independently, and
 * a deleted endpoint mid-loop (InvalidForeignKey) is skipped, matching the
 * Rails rescue.
 */
abstract class BaseService extends RootBaseService
{
    public function __construct(
        protected mixed $object,
        protected array $options = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        $organization = $this->currentOrganization();

        // Rails: `return if current_organization.webhook_endpoints.none?`
        if ($organization === null || $organization->webhookEndpoints()->doesntExist()) {
            return $result;
        }

        $payload = [
            'webhook_type' => $this->webhookType(),
            'object_type' => $this->objectType(),
            'organization_id' => $organization->id,
            $this->objectType() => $this->objectSerializer(),
        ];

        foreach ($organization->webhookEndpoints as $webhookEndpoint) {
            if (! $this->subscribed($webhookEndpoint)) {
                continue;
            }

            try {
                $webhook = $this->createWebhook($webhookEndpoint, $payload);
                SendHttpWebhookJob::dispatch($webhook)
                    ->onQueue(SendHttpWebhookJob::queueFor($this->webhookType()));
            } catch (QueryException $e) {
                // Rails rescues ActiveRecord::InvalidForeignKey — the webhook
                // endpoint was deleted while the fan-out was in progress.
                if ($e->getCode() !== '23503') {
                    throw $e;
                }

                Log::error("SendWebhookJob failed for deleted webhook endpoint {$webhookEndpoint->id}");
                continue;
            }
        }

        return $result;
    }

    /** Rails: `subscribed?` — nil event_types receives everything. */
    protected function subscribed(WebhookEndpoint $webhookEndpoint): bool
    {
        return $webhookEndpoint->subscribed($this->webhookType());
    }

    /**
     * Rails: `object_serializer` — the per-type builder overrides this with
     * the serialized payload for `object_type`.
     *
     * @return array<string, mixed>
     */
    protected function objectSerializer(): array
    {
        return [];
    }

    /** Rails: `current_organization` — the object's organization. */
    protected function currentOrganization(): ?object
    {
        if (is_array($this->object)) {
            return $this->object['organization'] ?? null;
        }

        return $this->object?->organization;
    }

    /** Rails: `webhook_type` — e.g. "customer.created". */
    abstract protected function webhookType(): string;

    /** Rails: `object_type` — the payload key holding the serialized object. */
    abstract protected function objectType(): string;

    /** Rails: `create_webhook`. */
    protected function createWebhook(WebhookEndpoint $webhookEndpoint, array $payload): Webhook
    {
        $webhook = new Webhook;
        $webhook->webhook_endpoint_id = $webhookEndpoint->id;
        $webhook->organization_id = $this->currentOrganization()?->id;
        $webhook->webhook_type = $this->webhookType();
        $webhook->endpoint = $webhookEndpoint->webhook_url;

        $webhook->object_id = is_array($this->object) ? ($this->object['id'] ?? null) : $this->object?->id;
        $webhook->object_type = is_array($this->object)
            ? ($this->object['class'] ?? null)
            : $this->object::class;

        $webhook->payload = $payload;
        $webhook->writeStatus('pending');
        $webhook->save();

        return $webhook;
    }
}
