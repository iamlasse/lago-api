<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\SalesforceIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' Integrations::SalesforceIntegration (app/models/
 * integrations/salesforce_integration.rb) — the settings accessor the
 * GraphQL type reads (the instance id of the connected Salesforce org).
 */
#[UseFactory(SalesforceIntegrationFactory::class)]
class SalesforceIntegration extends Integration
{
    use HasFactory;

    /** Rails: settings_accessors :instance_id. */
    public function instanceId(): ?string
    {
        return $this->getFromSettings('instance_id');
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::SALESFORCE_TYPE);
        });
    }
}
