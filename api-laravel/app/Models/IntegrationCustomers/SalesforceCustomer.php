<?php

declare(strict_types=1);

namespace App\Models\IntegrationCustomers;

use App\Models\IntegrationCustomer;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\SalesforceCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' IntegrationCustomers::SalesforceCustomer (app/models/
 * integration_customers/salesforce_customer.rb) — a plain STI row; the
 * Salesforce sync is Lago-side only (no provider call at creation).
 */
#[UseFactory(SalesforceCustomerFactory::class)]
class SalesforceCustomer extends IntegrationCustomer
{
    use HasFactory;

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::SALESFORCE_TYPE);
        });
    }
}
