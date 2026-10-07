<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use Throwable;
use App\Services\BaseResult;
use App\Jobs\SendHttpWebhookJob;
use App\Http\Client\LagoHttpError;
use App\Http\Client\LagoHttpClient;
use App\Http\Client\BlockedAddressError;
use Illuminate\Http\Client\ConnectionException;
use App\Services\BaseService as RootBaseService;

/**
 * Port of Rails' Webhooks::SendHttpService
 * (app/services/webhooks/send_http_service.rb) — one delivery attempt of one
 * webhook row, plus the retry bookkeeping.
 */
class SendHttpService extends RootBaseService
{
    /** Rails: MAX_STORED_RESPONSE_BYTES = 64.kilobytes. */
    public const int MAX_STORED_RESPONSE_BYTES = 64 * 1024;

    public function __construct(
        protected readonly \App\Models\Webhook $webhook,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        $this->webhook->endpoint = $this->webhook->webhookEndpoint->webhook_url;

        try {
            $response = $this->httpClient()->postWithResponse(
                $this->webhook->payload,
                $this->webhook->generateHeaders(),
            );

            $this->markWebhookAsSucceeded($response->status(), $response->body());

            return $result;
        } catch (LagoHttpError|BlockedAddressError|ConnectionException $e) {
            $retrying = (($this->webhook->retries + 1) < $this->retryLimit());
            $this->markWebhookAsUnsuccessful($e, $retrying);

            if ($retrying) {
                dispatch(new \App\Jobs\SendHttpWebhookJob($this->webhook))
                    ->onQueue(SendHttpWebhookJob::queueFor($this->webhook->webhook_type))
                    ->delay(now()->addSeconds($this->waitValue()));
            }

            return $result;
        }
    }

    protected function httpClient(): LagoHttpClient
    {
        return new LagoHttpClient(
            $this->webhook->webhookEndpoint->webhook_url,
            openTimeout: $this->timeoutSeconds(),
            readTimeout: $this->timeoutSeconds(),
            writeTimeout: $this->timeoutSeconds(),
            blockPrivateAddresses: true,
        );
    }

    /** Rails: ENV.fetch("LAGO_WEBHOOK_TIMEOUT_SECONDS", 30). */
    protected function timeoutSeconds(): int
    {
        return (int) config('lago.webhook.timeout_seconds', 30);
    }

    /** Rails: ENV.fetch("LAGO_WEBHOOK_ATTEMPTS", 3). */
    protected function retryLimit(): int
    {
        return (int) config('lago.webhook.attempts', 3);
    }

    protected function markWebhookAsSucceeded(int $httpStatus, ?string $body): void
    {
        $this->webhook->http_status = $httpStatus;
        $this->webhook->storeResponse($this->sanitizeBody($body) ?: []);
        $this->webhook->writeStatus('succeeded');
        $this->webhook->save();
    }

    protected function markWebhookAsUnsuccessful(Throwable $error, bool $retrying): void
    {
        if ($error instanceof LagoHttpError) {
            $this->webhook->http_status = (int) $error->errorCode;
            $this->webhook->storeResponse($this->sanitizeBody($error->errorBody));
        } elseif ($error instanceof BlockedAddressError) {
            $this->webhook->storeResponse('Destination address is not allowed');
        } else {
            // The raw message would tell a refused port from a timeout, a
            // port-scan oracle.
            $this->webhook->storeResponse('Connection failed');
        }

        $this->webhook->retries += 1;
        $this->webhook->last_retried_at = now();
        $this->webhook->writeStatus($retrying ? 'retrying' : 'failed');
        $this->webhook->save();
    }

    /**
     * Rails: `sanitize_body` — Net::HTTP bodies are binary, so invalid UTF-8
     * would fail the JSON storage after the endpoint got the webhook: force
     * UTF-8, scrub invalid sequences, cap at 64KB bytes, scrub again (the cap
     * can cut a multibyte character).
     */
    protected function sanitizeBody(mixed $body): mixed
    {
        if (! is_string($body)) {
            return $body;
        }

        // Ruby's String#scrub("") drops invalid UTF-8 sequences; mb's
        // substitute character must be "none" for the same behaviour.
        $scrubbed = static function (string $value): string {
            mb_substitute_character('none');

            return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        };

        return $scrubbed(mb_substr($scrubbed($body), 0, self::MAX_STORED_RESPONSE_BYTES, '8bit'));
    }

    /**
     * Rails: `wait_value` — based on the Rails Active Job polynomially_longer
     * wait algorithm: executions**4 + jitter + 2.
     */
    protected function waitValue(): float
    {
        $executions = $this->webhook->retries;

        return ($executions ** 4) + (mt_rand() / mt_getrandmax() * ($executions ** 4) * 0.15) + 2;
    }
}
