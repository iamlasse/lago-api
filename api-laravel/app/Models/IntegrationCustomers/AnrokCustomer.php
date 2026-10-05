<?php

declare(strict_types=1);

namespace App\Models\IntegrationCustomers;

use App\Models\IntegrationCustomer;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\AnrokCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' IntegrationCustomers::AnrokCustomer (app/models/
 * integration_customers/anrok_customer.rb) — a plain STI row. Anrok syncs
 * the real customer on the first document sync; Lago only stores the row
 * (IntegrationCustomers::AnrokService#create).
 */
#[UseFactory(AnrokCustomerFactory::class)]
class AnrokCustomer extends IntegrationCustomer
{
    use HasFactory;

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::ANROK_TYPE);
        });
    }
}
