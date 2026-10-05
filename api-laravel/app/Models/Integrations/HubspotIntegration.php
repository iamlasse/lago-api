<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\HubspotIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' Integrations::HubspotIntegration (app/models/
 * integrations/hubspot_integration.rb) — the settings/secrets accessors the
 * aggregator services and the GraphQL type read. The connection id lives in
 * the secrets; the targeted object and the deployed-properties version
 * markers live in the settings.
 */
#[UseFactory(HubspotIntegrationFactory::class)]
class HubspotIntegration extends Integration
{
    use HasFactory;

    /** Rails: TARGETED_OBJECTS. */
    public const array TARGETED_OBJECTS = ['companies', 'contacts'];

    /** Rails: settings_accessors list. */
    public const array SETTINGS_KEYS = [
        'default_targeted_object',
        'sync_subscriptions',
        'sync_invoices',
        'subscriptions_object_type_id',
        'invoices_object_type_id',
        'companies_properties_version',
        'contacts_properties_version',
        'subscriptions_properties_version',
        'invoices_properties_version',
        'portal_id',
    ];

    /** Rails: secrets_accessors :connection_id. */
    public function connectionId(): ?string
    {
        return $this->getFromSecrets('connection_id');
    }

    public function defaultTargetedObject(): ?string
    {
        return $this->getFromSettings('default_targeted_object');
    }

    public function syncInvoices(): ?bool
    {
        return $this->getFromSettings('sync_invoices');
    }

    public function syncSubscriptions(): ?bool
    {
        return $this->getFromSettings('sync_subscriptions');
    }

    public function portalId(): ?string
    {
        return $this->getFromSettings('portal_id');
    }

    public function companiesPropertiesVersion(): mixed
    {
        return $this->getFromSettings('companies_properties_version');
    }

    public function contactsPropertiesVersion(): mixed
    {
        return $this->getFromSettings('contacts_properties_version');
    }

    public function subscriptionsPropertiesVersion(): mixed
    {
        return $this->getFromSettings('subscriptions_properties_version');
    }

    public function invoicesPropertiesVersion(): mixed
    {
        return $this->getFromSettings('invoices_properties_version');
    }

    public function invoicesObjectTypeId(): ?string
    {
        return $this->getFromSettings('invoices_object_type_id');
    }

    public function subscriptionsObjectTypeId(): ?string
    {
        return $this->getFromSettings('subscriptions_object_type_id');
    }

    /** Rails: `companies_object_type_id` — the hardcoded HubSpot company object. */
    public function companiesObjectTypeId(): string
    {
        return '0-2';
    }

    /** Rails: `contacts_object_type_id` — the hardcoded HubSpot contact object. */
    public function contactsObjectTypeId(): string
    {
        return '0-1';
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::HUBSPOT_TYPE);
        });
    }
}
