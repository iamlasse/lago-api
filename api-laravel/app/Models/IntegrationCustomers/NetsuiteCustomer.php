<?php

declare(strict_types=1);

namespace App\Models\IntegrationCustomers;

use App\Models\IntegrationCustomer;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\NetsuiteCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' IntegrationCustomers::NetsuiteCustomer (app/models/
 * integration_customers/netsuite_customer.rb) — settings_accessors
 * :subsidiary_id; the external_customer_id is the Netsuite customer id
 * returned by the Nango contacts create call.
 */
#[UseFactory(NetsuiteCustomerFactory::class)]
class NetsuiteCustomer extends IntegrationCustomer
{
    use HasFactory;

    /** Rails: settings_accessors :subsidiary_id. */
    public function subsidiaryId(): ?string
    {
        return $this->getFromSettings('subsidiary_id');
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::NETSUITE_TYPE);
        });
    }
}
