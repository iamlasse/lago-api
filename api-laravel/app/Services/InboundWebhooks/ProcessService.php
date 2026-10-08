<?php

declare(strict_types=1);

namespace App\Services\InboundWebhooks;

use Throwable;
use RuntimeException;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\InboundWebhook;
use App\Services\PaymentProviders\Stripe\HandleIncomingWebhookService as StripeHandler;
use App\Services\PaymentProviders\Moneyhash\HandleIncomingWebhookService as MoneyhashHandler;

/**
 * Port of Rails' InboundWebhooks::ProcessService
 * (app/services/inbound_webhooks/process_service.rb) — the status-guarded
 * wrapper around the per-source webhook handlers, shared by the inbound
 * entrypoint job and the retry clock (InboundWebhooksRetryJob).
 *
 * Lifecycle: pending -> processing (marks processing_at, opens the 2h
 * window) -> succeeded/failed. A webhook still "processing" inside the
 * window is assumed to be mid-flight elsewhere and is left alone; past the
 * window (or an old pending that never got picked up) it becomes
 * retriable. Already-failed/succeeded webhooks are never reprocessed —
 * only the clock's retriable scope re-feeds them.
 */
class ProcessService extends BaseService
{
    private const WEBHOOK_HANDLER_SERVICES = [
        'stripe' => StripeHandler::class,
        'moneyhash' => MoneyhashHandler::class,
    ];

    public function __construct(private readonly InboundWebhook $inboundWebhook)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('inbound_webhook');

        if ($this->withinProcessingWindow()) {
            return $result;
        }

        if ($this->inboundWebhook->status === 'failed') {
            return $result;
        }

        if ($this->inboundWebhook->status === 'succeeded') {
            return $result;
        }

        try {
            $this->inboundWebhook->markProcessing();

            $source = $this->inboundWebhook->source ?? '';

            $handlerClass = self::WEBHOOK_HANDLER_SERVICES[$source]
                ?? throw new RuntimeException("Invalid inbound webhook source: {$source}");

            $handlerResult = $handlerClass::call(inboundWebhook: $this->inboundWebhook);

            if (! $handlerResult->success()) {
                $this->inboundWebhook->markFailed();

                return $handlerResult;
            }

            $this->inboundWebhook->markSucceeded();

            $result->inbound_webhook = $this->inboundWebhook;

            return $result;
        } catch (Throwable $e) {
            $this->inboundWebhook->markFailed();
            throw $e;
        }
    }

    private function withinProcessingWindow(): bool
    {
        return $this->inboundWebhook->isProcessing()
            && $this->inboundWebhook->processing_at !== null
            && $this->inboundWebhook->processing_at->gt(
                now()->subMinutes(InboundWebhook::WEBHOOK_PROCESSING_WINDOW_MINUTES),
            );
    }
}
