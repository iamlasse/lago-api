<?php

declare(strict_types=1);

namespace App\Models\IntegrationCustomers;

use App\Models\IntegrationCustomer;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\XeroCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' IntegrationCustomers::XeroCustomer (app/models/
 * integration_customers/xero_customer.rb) — a plain STI row; the
 * external_customer_id is the Xero contact id returned by the Nango
 * contacts create call.
 */
#[UseFactory(XeroCustomerFactory::class)]
class XeroCustomer extends IntegrationCustomer
{
    use HasFactory;

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::XERO_TYPE);
        });
    }
}
