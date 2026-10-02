<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Customers::DestroyService
 * (app/services/customers/destroy_service.rb): discards the customer
 * (soft delete) and schedules the relations termination.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Customers::TerminateRelationsJob — subscription/wallet
 *   termination lands with the subscriptions slice; the emission point is
 *   marked below.
 * - TODO(port): activity log middleware (activity_loggable customer.deleted).
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customer');

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        // Rails: customer.discard! — soft delete (deleted_at = now).
        $this->customer->delete();

        // TODO(port): Customers::TerminateRelationsJob.perform_later(customer_id:)

        $result->customer = $this->customer;

        return $result;
    }
}
