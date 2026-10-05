<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Flutterwave;

use Throwable;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' PaymentProviders::Flutterwave::HandleEventService — only
 * "charge.completed" payloads dispatch to ChargeCompletedService; a handler
 * failure is logged and swallowed (Rails: rescue => Rails.logger.error).
 */
class HandleEventService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly string $eventJson,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        $event = json_decode($this->eventJson, true, 512, JSON_THROW_ON_ERROR);

        if (($event['event'] ?? null) !== 'charge.completed') {
            return $result;
        }

        try {
            Webhooks\ChargeCompletedService::callBang(
                organizationId: $this->organization->id,
                eventJson: $this->eventJson,
            );
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            Log::error('Flutterwave event processing error: '.$e->getMessage());
        }

        return $result;
    }
}
