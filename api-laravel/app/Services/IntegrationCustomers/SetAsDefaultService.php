<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Models\IntegrationCustomer;

/**
 * Port of Rails' IntegrationCustomers::SetAsDefaultService
 * (app/services/integration_customers/set_as_default_service.rb) — the
 * default flag moves within the connection's category.
 */
class SetAsDefaultService extends BaseService
{
    public function __construct(
        private readonly ?IntegrationCustomer $integration_customer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_customer');

        if ($this->integration_customer === null) {
            return $result->notFoundFailure('integration_customer');
        }

        if ((bool) $this->integration_customer->is_default) {
            $result->integration_customer = $this->integration_customer;

            return $result;
        }

        DB::transaction(function (): void {
            $this->integration_customer->customer->integrationCustomers()
                ->where('category', $this->integration_customer->category)
                ->where('id', '!=', $this->integration_customer->id)
                ->update(['is_default' => false, 'updated_at' => now()]);

            $this->integration_customer->is_default = true;
            $this->integration_customer->save();
        });

        $result->integration_customer = $this->integration_customer;

        return $result;
    }
}
