<?php

declare(strict_types=1);

namespace App\Jobs;

use LogicException;
use App\Models\Webhook;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Webhooks\Invoices\CreatedService as InvoiceCreatedService;
use App\Services\Webhooks\Invoices\DraftedService as InvoiceDraftedService;
use App\Services\Webhooks\Customers\CreatedService as CustomerCreatedService;
use App\Services\Webhooks\Customers\UpdatedService as CustomerUpdatedService;
use App\Services\Webhooks\Subscriptions\StartedService as SubscriptionStartedService;
use App\Services\Webhooks\Subscriptions\UpdatedService as SubscriptionUpdatedService;
use App\Services\Webhooks\Subscriptions\CanceledService as SubscriptionCanceledService;
use App\Services\Webhooks\Subscriptions\TerminatedService as SubscriptionTerminatedService;

/**
 * Port of Rails' SendWebhookJob (app/jobs/send_webhook_job.rb).
 *
 * WEBHOOK_SERVICES registers the M1 event types only; every other type in
 * Rails' hash (alert.triggered, wallet.*, credit_note.*, ...) still has to be
 * registered as its builder service is ported. Unknown types raise exactly
 * like Rails' `raise(NotImplementedError)`.
 *
 * Rails' `retry_on ActiveJob::DeserializationError` (6 polynomial waits) has
 * no direct Laravel equivalent — a serialized model that vanished raises
 * ModelNotFoundException, retried per the worker's $tries, not here.
 */
class SendWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Port of the WEBHOOK_SERVICES hash — M1 surface.
     *
     * @var array<string, class-string>
     */
    public const array WEBHOOK_SERVICES = [
        // "alert.triggered" => Webhooks\UsageMonitoring\AlertTriggeredService,
        // "billable_metric.created" => ..., (billable metrics webhooks — later slice)
        'customer.created' => CustomerCreatedService::class,
        'customer.updated' => CustomerUpdatedService::class,
        // "customer.tax_provider_error", "customer.vies_check", integration /
        // payment-provider customer types — later slices.
        'invoice.created' => InvoiceCreatedService::class,
        'invoice.drafted' => InvoiceDraftedService::class,
        // "invoice.generated", "invoice.voided", ... — later slices.
        'subscription.started' => SubscriptionStartedService::class,
        'subscription.updated' => SubscriptionUpdatedService::class,
        'subscription.terminated' => SubscriptionTerminatedService::class,
        'subscription.canceled' => SubscriptionCanceledService::class,
        // "subscription.incomplete", "subscription.trial_ended", ... — later slices.
    ];

    /** Rails: HIGH_PRIORITY_WEBHOOK_TYPES. */
    public const array HIGH_PRIORITY_WEBHOOK_TYPES = ['alert.triggered'];

    /**
     * Rails: `queue_as { self.class.queue_for(arguments.first) }`.
     */
    public function __construct(
        public readonly string $webhookType,
        public readonly mixed $object,
        public readonly array $options = [],
        public readonly ?string $webhookId = null,
    ) {
        $this->onQueue(static::queueFor($this->webhookType));
    }

    /**
     * Rails: `self.queue_for` — :webhook unless SIDEKIQ_WEBHOOK routes to the
     * dedicated workers (alert.triggered gets the high-priority one).
     */
    public static function queueFor(?string $webhookType = null): string
    {
        if (filter_var(env('SIDEKIQ_WEBHOOK'), FILTER_VALIDATE_BOOL)) {
            return in_array($webhookType, static::HIGH_PRIORITY_WEBHOOK_TYPES, true)
                ? 'webhook_worker_high_priority'
                : 'webhook_worker';
        }

        return 'webhook';
    }

    /**
     * Port of the `perform_later` override — skip enqueueing when the
     * organization has no webhook endpoints, so jobs don't run only to return
     * early. With a webhook_id the check is skipped (the webhook row, hence
     * the endpoint, is assumed to exist).
     */
    public static function performLater(
        string $webhookType,
        mixed $object,
        array $options = [],
        ?string $webhookId = null,
    ): ?static {
        if ($webhookId === null && ! static::objectHasEndpoints($object)) {
            return null;
        }

        static::dispatch($webhookType, $object, $options, $webhookId);

        return null;
    }

    /**
     * Rails: `perform` — legacy webhook_id enqueues go straight to the HTTP
     * job; everything else resolves the registry and runs the builder.
     */
    public function handle(): void
    {
        if (! array_key_exists($this->webhookType, static::WEBHOOK_SERVICES)) {
            // Rails: `raise(NotImplementedError) unless WEBHOOK_SERVICES.include?`
            throw new LogicException(
                "No webhook service registered for webhook_type '{$this->webhookType}'",
            );
        }

        if ($this->webhookId !== null) {
            // NOTE: temporary condition to handle legacy enqueued jobs.
            SendHttpWebhookJob::dispatch(Webhook::findOrFail($this->webhookId));

            return;
        }

        $builder = static::WEBHOOK_SERVICES[$this->webhookType];
        $builder::call(object: $this->object, options: $this->options);
    }

    /** Rails: `object.organization.webhook_endpoints.none?`. */
    protected static function objectHasEndpoints(mixed $object): bool
    {
        $organization = match (true) {
            $object instanceof Organization => $object,
            is_object($object) && method_exists($object, 'organization') => $object->organization,
            is_array($object) && isset($object['organization_id']) => Organization::find($object['organization_id']),
            default => null,
        };

        return $organization !== null && $organization->webhookEndpoints()->exists();
    }
}
