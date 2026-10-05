<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Cashfree;

use Throwable;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' PaymentProviders::Cashfree::HandleEventService —
 * PAYMENT_LINK_EVENT payloads dispatch to PaymentLinkEventService; any
 * other event type is ignored (Rails' EVENT_MAPPING.fetch returning nil).
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

        try {
            $event = json_decode($this->eventJson, true, 512, JSON_THROW_ON_ERROR);

            if (($event['type'] ?? null) === 'PAYMENT_LINK_EVENT') {
                Webhooks\PaymentLinkEventService::callBang(
                    organizationId: $this->organization->id,
                    eventJson: $this->eventJson,
                );
            }

            return $result;
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }
}
