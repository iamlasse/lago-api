<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\Webhook;
use App\Services\BaseResult;
use App\Jobs\SendHttpWebhookJob;
use App\Services\BaseService as RootBaseService;

/**
 * Port of Rails' Webhooks::RetryService
 * (app/services/webhooks/retry_service.rb) — the manual retry endpoint's
 * service; the controller is a later slice.
 */
class RetryService extends RootBaseService
{
    public function __construct(
        protected readonly ?Webhook $webhook,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('webhook');

        if ($this->webhook === null) {
            return $result->notFoundFailure('webhook');
        }

        if ($this->webhook->succeeded()) {
            return $result->notAllowedFailure('is_succeeded');
        }

        dispatch(new \App\Jobs\SendHttpWebhookJob($this->webhook))
            ->onQueue(SendHttpWebhookJob::queueFor($this->webhook->webhook_type));

        $result->webhook = $this->webhook;

        return $result;
    }
}
