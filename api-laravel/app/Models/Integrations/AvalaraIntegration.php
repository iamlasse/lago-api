<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\AvalaraIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' Integrations::AvalaraIntegration (app/models/integrations/
 * avalara_integration.rb). Avalara is a PREMIUM tax integration — the
 * create/update services gate on Organization#avalaraEnabled.
 *
 * Rails: validates :company_code, :connection_id, :account_id, :license_key,
 * presence: true (enforced by the create/update services in this port);
 * settings_accessors :account_id, :company_code, :company_id;
 * secrets_accessors :connection_id, :license_key.
 */
#[UseFactory(AvalaraIntegrationFactory::class)]
class AvalaraIntegration extends Integration
{
    use HasFactory;

    // -- settings_accessors ---------------------------------------------------

    public function accountId(): ?string
    {
        return $this->getFromSettings('account_id');
    }

    public function companyCode(): ?string
    {
        return $this->getFromSettings('company_code');
    }

    public function companyId(): ?string
    {
        return $this->getFromSettings('company_id');
    }

    // -- secrets_accessors ----------------------------------------------------

    public function connectionId(): ?string
    {
        return $this->getFromSecrets('connection_id');
    }

    public function licenseKey(): ?string
    {
        return $this->getFromSecrets('license_key');
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::AVALARA_TYPE);
        });
    }
}
