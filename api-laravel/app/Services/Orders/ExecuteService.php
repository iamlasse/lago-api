<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Quote;
use App\Support\License;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Orders\OneOff\ExecuteService as OneOffExecuteService;

/**
 * Port of Rails' Orders::ExecuteService
 * (app/services/orders/execute_service.rb) — the premium/feature-flag gate
 * and the dispatch to the concrete service for the order's order type.
 */
class ExecuteService extends BaseService
{
    public function __construct(
        private readonly ?Order $order,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('order');
        $order = $this->order;

        if ($order === null) {
            return $result->notFoundFailure('order');
        }

        if (! $this->orderFormsEnabled($order->organization)) {
            return $result->forbiddenFailure('feature_unavailable');
        }

        $orderType = $order->orderType();

        return match (true) {
            $orderType === Quote::ORDER_TYPES['one_off'] => OneOffExecuteService::call(order: $order),
            $orderType === Quote::ORDER_TYPES['subscription_creation'] => SubscriptionCreation\ExecuteService::call(order: $order),
            $orderType === Quote::ORDER_TYPES['subscription_amendment'] => SubscriptionAmendment\ExecuteService::call(order: $order),
            default => $result->singleValidationFailure('unsupported_order_type', 'order_type'),
        };
    }

    /**
     * Rails: OrderForms::Premium#order_forms_enabled? —
     * License.premium? && organization.feature_flag_enabled?(:order_forms).
     */
    private function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
