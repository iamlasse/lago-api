<?php

declare(strict_types=1);

namespace App\Models\IntegrationCustomers;

use App\Models\IntegrationCustomer;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\AvalaraCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' IntegrationCustomers::AvalaraCustomer (app/models/
 * integration_customers/avalara_customer.rb) — a plain STI row; the
 * external_customer_id is the Avalara contact id returned by the Nango
 * contacts create call.
 */
#[UseFactory(AvalaraCustomerFactory::class)]
class AvalaraCustomer extends IntegrationCustomer
{
    use HasFactory;

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::AVALARA_TYPE);
        });
    }
}
