<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

use App\Models\Integration;
use App\Jobs\SendWebhookJob;
use App\Http\Client\LagoHttpError;
use App\Http\Client\LagoHttpClient;
use App\Services\BaseService as RootBaseService;

/**
 * Port of Rails' Integrations::Aggregator::BaseService
 * (app/services/integrations/aggregator/base_service.rb) — the shared
 * Nango plumbing: endpoint/headers, provider key resolution, the error
 * code/message extractors and the integration error webhook emission.
 *
 * TODO(port): the Throttling subsystem (Throttling.for(:anrok).check) —
 * Rails rate-limits provider calls through the throttlings machinery, which
 * is not ported yet; every `throttle!(:provider)` call site is a no-op here.
 */
abstract class BaseService extends RootBaseService
{
    /** Rails: BASE_URL. */
    public const string BASE_URL = 'https://api.nango.dev/';

    public const string REQUEST_LIMIT_ERROR_CODE = 'SSS_REQUEST_LIMIT_EXCEEDED';

    public const string BAD_GATEWAY_ERROR = '502 Bad Gateway';

    public const string TASK_IN_PROGRESS_PATTERN = '/\bTask\b.*\bis in progress\b/';

    public const string TASK_EXPIRED_PATTERN = '/\bTask\b.*\bexpired\b/';

    public const string ORCHESTRATOR_FAILURE_PATTERN =
        '/POST https?:\/\/nango-orchestrator-svc\.nango\/v1\/immediate failed/';

    public function __construct(protected readonly ?Integration $integration)
    {
        parent::__construct();
    }

    abstract public function actionPath(): string;

    /** Rails: `self.retryable_errors`. */
    public static function retryableErrors(): array
    {
        return [
            BadGatewayError::class,
            RequestLimitError::class,
            OutOfMemoryError::class,
            TaskInProgressError::class,
            TaskExpiredError::class,
            OrchestratorFailureError::class,
            ServerContentionError::class,
            TimeoutError::class,
        ];
    }

    /**
     * Rails: `provider` — the short provider key resolved from the STI type.
     */
    protected function provider(): ?string
    {
        return match ($this->integration?->type) {
            'Integrations::NetsuiteIntegration' => 'netsuite',
            'Integrations::XeroIntegration' => 'xero',
            'Integrations::AnrokIntegration' => 'anrok',
            'Integrations::AvalaraIntegration' => 'avalara',
            'Integrations::HubspotIntegration' => 'hubspot',
            default => null,
        };
    }

    /**
     * Rails: `provider_key` — the Nango provider config key. Avalara points
     * at the sandbox config outside production.
     */
    protected function providerKey(): ?string
    {
        return match ($this->integration?->type) {
            'Integrations::NetsuiteIntegration' => 'netsuite-tba',
            'Integrations::XeroIntegration' => 'xero',
            'Integrations::AnrokIntegration' => 'anrok',
            'Integrations::AvalaraIntegration' => app()->isProduction() ? 'avalara' : 'avalara-sandbox',
            'Integrations::HubspotIntegration' => 'hubspot',
            default => null,
        };
    }

    protected function http_client(): LagoHttpClient
    {
        return new LagoHttpClient($this->endpointUrl());
    }

    protected function endpointUrl(): string
    {
        return self::BASE_URL.$this->actionPath();
    }

    protected function headers(): array
    {
        return [
            'Connection-Id' => $this->integration->getFromSecrets('connection_id'),
            'Authorization' => 'Bearer '.$this->secret_key(),
        ];
    }

    /** Rails: `secret_key` — the ENV NANGO secret. */
    protected function secret_key(): ?string
    {
        return env('NANGO_SECRET_KEY');
    }

    protected function deliver_error_webhook(object $customer, string $code, string $message): void
    {
        SendWebhookJob::performLater($this->errorWebhookCode(), $customer, [
            'provider' => $this->provider(),
            'provider_code' => $this->integration?->code,
            'provider_error' => [
                'message' => $message,
                'error_code' => $code,
            ],
        ]);
    }

    protected function deliver_integration_error_webhook(string $code, string $message): void
    {
        SendWebhookJob::performLater('integration.provider_error', $this->integration, [
            'provider' => $this->provider(),
            'provider_code' => $this->integration?->code,
            'provider_error' => [
                'message' => $message,
                'error_code' => $code,
            ],
        ]);
    }

    protected function deliver_tax_error_webhook(object $customer, string $code, string $message): void
    {
        SendWebhookJob::performLater('customer.tax_provider_error', $customer, [
            'provider' => $this->provider(),
            'provider_code' => $this->integration?->code,
            'provider_error' => [
                'message' => $message,
                'error_code' => $code,
            ],
        ]);
    }

    protected function errorWebhookCode(): string
    {
        return match ($this->provider()) {
            'hubspot' => 'customer.crm_provider_error',
            'avalara' => 'customer.tax_provider_error',
            default => 'customer.accounting_provider_error',
        };
    }

    /**
     * Rails: `code(error)` — the error code dug out of the JSON body
     * (first present key of the documented paths, else "unexpected_error").
     */
    protected function code(LagoHttpError $error): string
    {
        $json = $this->jsonMessage($error);

        return $this->safeDigStr($json, 'type')
            ?? $this->safeDigStr($json, 'error', 'payload', 'name')
            ?? $this->safeDigStr($json, 'error', 'payload', 'error', 'code')
            ?? $this->safeDigStr($json, 'error', 'code')
            ?? 'unexpected_error';
    }

    /**
     * Rails: `message(error)` — the message dug out of the JSON body (first
     * present key of the documented paths, else the whole JSON).
     */
    protected function message(LagoHttpError $error): string
    {
        $json = $this->jsonMessage($error);

        return $this->safeDigStr($json, 'payload', 'message')
            ?? $this->safeDigStr($json, 'error', 'payload', 'message')
            ?? $this->safeDigStr($json, 'error', 'payload', 'error')
            ?? $this->safeDigStr($json, 'error', 'payload', 'error', 'message')
            ?? $this->safeDigStr($json, 'error', 'message')
            ?? (string) json_encode($json);
    }

    protected function jsonMessage(LagoHttpError $error): mixed
    {
        if (is_string($error->errorBody)) {
            $decoded = json_decode($error->errorBody, true);

            return is_array($decoded) ? $decoded : $error->errorBody;
        }

        return $error->errorBody;
    }

    /**
     * Rails: `safe_dig_str` — walks nested hashes, returning nil for any
     * non-hash hop and only strings with content.
     */
    protected function safeDigStr(mixed $object, string ...$keys): ?string
    {
        $value = $object;

        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;

            if ($value === null || $value === '') {
                return null;
            }
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function request_limit_error(LagoHttpError $error): bool
    {
        return is_string($error->errorBody)
            && str_contains($error->errorBody, self::REQUEST_LIMIT_ERROR_CODE);
    }

    protected function bad_gateway_error(LagoHttpError $error): bool
    {
        return (is_string($error->errorCode) && $error->errorCode === '502')
            || (is_string($error->errorBody) && str_contains($error->errorBody, self::BAD_GATEWAY_ERROR));
    }

    protected function task_in_progress_error(LagoHttpError $error): bool
    {
        return $this->code($error) === 'action_script_failure'
            && (is_string($error->errorBody) || is_array($error->errorBody))
            && preg_match(self::TASK_IN_PROGRESS_PATTERN, $this->message($error)) === 1;
    }

    protected function task_expired_error(LagoHttpError $error): bool
    {
        return $this->code($error) === 'action_script_failure'
            && preg_match(self::TASK_EXPIRED_PATTERN, $this->message($error)) === 1;
    }

    protected function orchestrator_failure_error(LagoHttpError $error): bool
    {
        return preg_match(self::ORCHESTRATOR_FAILURE_PATTERN, $this->message($error)) === 1;
    }
}
