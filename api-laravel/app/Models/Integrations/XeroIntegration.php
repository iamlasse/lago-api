<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\XeroIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' Integrations::XeroIntegration (app/models/integrations/
 * xero_integration.rb). Rails: validates :connection_id, presence: true
 * (enforced by the create/update services in this port);
 * settings_accessors :sync_credit_notes, :sync_invoices, :sync_payments;
 * secrets_accessors :connection_id.
 */
#[UseFactory(XeroIntegrationFactory::class)]
class XeroIntegration extends Integration
{
    use HasFactory;

    /** Rails: settings_accessors :sync_credit_notes. */
    public function syncCreditNotes(): ?bool
    {
        return $this->getFromSettings('sync_credit_notes');
    }

    /** Rails: settings_accessors :sync_invoices. */
    public function syncInvoices(): ?bool
    {
        return $this->getFromSettings('sync_invoices');
    }

    /** Rails: settings_accessors :sync_payments. */
    public function syncPayments(): ?bool
    {
        return $this->getFromSettings('sync_payments');
    }

    /** Rails: secrets_accessors :connection_id. */
    public function connectionId(): ?string
    {
        return $this->getFromSecrets('connection_id');
    }

    /** Rails: `external_id_key` — "item_code" for Xero. */
    public function externalIdKey(): string
    {
        return 'item_code';
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::XERO_TYPE);
        });
    }
}
