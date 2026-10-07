<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `integration_customers` (Rails'
 * IntegrationCustomers::BaseCustomer — single-table inheritance on `type`;
 * subclasses are not ported yet, so the base model carries the columns).
 */
#[Fillable([
    'integration_id',
    'customer_id',
    'external_customer_id',
    'type',
    'settings',
    'organization_id',
    'code',
    'is_default',
    'category',
])]
#[Table(name: 'integration_customers')]
class IntegrationCustomer extends BaseModel
{
    use HasFactory;

    /** Rails: IntegrationCustomers::BaseCustomer::CATEGORIES. */
    public const CATEGORIES = [
        'payment' => 'payment',
        'tax' => 'tax',
        'accounting' => 'accounting',
        'crm' => 'crm',
    ];

    /** Rails STI type strings (frozen `type` column values). */
    public const ANROK_TYPE = 'IntegrationCustomers::AnrokCustomer';

    public const AVALARA_TYPE = 'IntegrationCustomers::AvalaraCustomer';

    public const HUBSPOT_TYPE = 'IntegrationCustomers::HubspotCustomer';

    public const SALESFORCE_TYPE = 'IntegrationCustomers::SalesforceCustomer';

    public const XERO_TYPE = 'IntegrationCustomers::XeroCustomer';

    public const NETSUITE_TYPE = 'IntegrationCustomers::NetsuiteCustomer';

    /** Rails: CATEGORY_BY_TYPE (app/models/integration_customers/base_customer.rb). */
    public const CATEGORY_BY_TYPE = [
        self::ANROK_TYPE => 'tax',
        self::AVALARA_TYPE => 'tax',
        self::NETSUITE_TYPE => 'accounting',
        self::XERO_TYPE => 'accounting',
        self::HUBSPOT_TYPE => 'crm',
        self::SALESFORCE_TYPE => 'crm',
    ];

    /** Rails: BaseCustomer.customer_type(:anrok) — the STI type per provider key. */
    public const PROVIDER_TYPES = [
        'anrok' => self::ANROK_TYPE,
        'avalara' => self::AVALARA_TYPE,
        'hubspot' => self::HUBSPOT_TYPE,
        'salesforce' => self::SALESFORCE_TYPE,
        'netsuite' => self::NETSUITE_TYPE,
        'xero' => self::XERO_TYPE,
    ];

    /** Rails: TAX_INTEGRATION_TYPES. */
    public const TAX_INTEGRATION_TYPES = [self::ANROK_TYPE, self::AVALARA_TYPE];

    /** Rails: ACCOUNTING_INTEGRATION_TYPES (the `accounting_kind` scope). */
    public const ACCOUNTING_INTEGRATION_TYPES = [self::NETSUITE_TYPE, self::XERO_TYPE];

    /**
     * Rails: BaseCustomer#tax_kind? — the customer_type is one of the tax
     * provider kinds (anrok / avalara).
     */
    public function taxKind(): bool
    {
        return in_array($this->type, [self::ANROK_TYPE, self::AVALARA_TYPE], true);
    }

    /**
     * Rails: `scope :accounting_kind` — the accounting provider kinds
     * (netsuite / xero).
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function accountingKind($query)
    {
        return $query->whereIn('type', self::ACCOUNTING_INTEGRATION_TYPES);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** Rails: SettingsStorable#get_from_settings(key) over the settings jsonb. */
    public function getFromSettings(string $key): mixed
    {
        return data_get($this->settings, $key);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
