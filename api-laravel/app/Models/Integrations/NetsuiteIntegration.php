<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\NetsuiteIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' Integrations::NetsuiteIntegration (app/models/integrations/
 * netsuite_integration.rb). Rails: validates :connection_id, :client_secret,
 * :client_id, :account_id, :script_endpoint_url, presence: true (enforced by
 * the create/update services in this port);
 * settings_accessors :client_id, :legacy_script, :sync_credit_notes,
 * :sync_invoices, :sync_payments, :script_endpoint_url, :token_id;
 * secrets_accessors :connection_id, :client_secret, :token_secret.
 */
#[UseFactory(NetsuiteIntegrationFactory::class)]
class NetsuiteIntegration extends Integration
{
    use HasFactory;

    /** Rails: settings_accessors :client_id. */
    public function clientId(): ?string
    {
        return $this->getFromSettings('client_id');
    }

    /** Rails: settings_accessors :legacy_script. */
    public function legacyScript(): ?bool
    {
        return $this->getFromSettings('legacy_script');
    }

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

    /** Rails: settings_accessors :script_endpoint_url. */
    public function scriptEndpointUrl(): ?string
    {
        return $this->getFromSettings('script_endpoint_url');
    }

    /** Rails: settings_accessors :token_id. */
    public function tokenId(): ?string
    {
        return $this->getFromSettings('token_id');
    }

    /** Rails: secrets_accessors :connection_id. */
    public function connectionId(): ?string
    {
        return $this->getFromSecrets('connection_id');
    }

    /** Rails: secrets_accessors :client_secret. */
    public function clientSecret(): ?string
    {
        return $this->getFromSecrets('client_secret');
    }

    /** Rails: secrets_accessors :token_secret. */
    public function tokenSecret(): ?string
    {
        return $this->getFromSecrets('token_secret');
    }

    /**
     * Rails: `account_id` — read back from settings.
     */
    public function accountId(): ?string
    {
        return $this->getFromSettings('account_id');
    }

    /**
     * Rails: `account_id=` override — pushes the normalized value into
     * settings (`value&.downcase&.strip&.split(" ")&.join("-")`). The port
     * uses this explicit setter where Rails assigns the attribute.
     */
    public function setAccountId(?string $value): void
    {
        $normalized = $value !== null
            ? str_replace(' ', '-', mb_strtolower(mb_trim($value)))
            : null;

        $settings = $this->settings ?? [];
        $settings['account_id'] = $normalized;
        $this->settings = $settings;
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::NETSUITE_TYPE);
        });
    }
}
