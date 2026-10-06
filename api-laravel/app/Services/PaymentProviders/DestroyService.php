<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' PaymentProviders::DestroyService
 * (app/services/payment_providers/destroy_service.rb) — soft-delete the
 * provider, soft-delete its payment provider customers and clear the
 * customer payment_provider pointers, in one transaction.
 *
 * TODO(port): the webhook-unregister job and
 * Utils::SecurityLog.produce("integration.deleted").
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?PaymentProvider $paymentProvider,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_provider');

        if ($this->paymentProvider === null) {
            return $result->notFoundFailure('payment_provider');
        }

        $customerIds = $this->paymentProvider->paymentProviderCustomers()->pluck('customer_id')->all();

        DB::transaction(function () use ($customerIds): void {
            $now = now();

            // Rails: update_all(updated_at:, deleted_at:) — soft delete
            // without touching the timestamps' normal pipeline.
            $this->paymentProvider->paymentProviderCustomers()->update(['updated_at' => $now, 'deleted_at' => $now]);

            $this->paymentProvider->delete();

            Customer::query()
                ->whereIn('id', $customerIds)
                ->update(['payment_provider' => null, 'payment_provider_code' => null]);
        });

        // TODO(port): the webhook-unregister job + the security log.

        $result->payment_provider = $this->paymentProvider;

        return $result;
    }
}
