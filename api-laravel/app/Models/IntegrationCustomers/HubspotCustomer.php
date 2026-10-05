<?php

declare(strict_types=1);

namespace App\Models\IntegrationCustomers;

use App\Models\IntegrationCustomer;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\HubspotCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' IntegrationCustomers::HubspotCustomer (app/models/
 * integration_customers/hubspot_customer.rb) — the targeted object and the
 * contact email live in the settings (SettingsStorable accessors).
 */
#[UseFactory(HubspotCustomerFactory::class)]
class HubspotCustomer extends IntegrationCustomer
{
    use HasFactory;

    public function targetedObject(): ?string
    {
        return $this->getFromSettings('targeted_object');
    }

    public function email(): ?string
    {
        return $this->getFromSettings('email');
    }

    /**
     * Rails: `object_type` — "contact" when the targeted object is contacts,
     * else "company".
     */
    public function objectType(): string
    {
        return $this->targetedObject() === 'contacts' ? 'contact' : 'company';
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::HUBSPOT_TYPE);
        });
    }
}
