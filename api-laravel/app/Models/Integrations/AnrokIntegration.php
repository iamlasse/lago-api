<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\AnrokIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' Integrations::AnrokIntegration (app/models/integrations/
 * anrok_integration.rb). Anrok is a NON-premium tax integration (Organization
 * NON_PREMIUM_INTEGRATIONS) — the create/update services only gate on the
 * license, not on a premium-integration flag.
 *
 * Rails: validates :connection_id, :api_key, presence: true (enforced by the
 * create/update services in this port); secrets_accessors :connection_id,
 * :api_key.
 */
#[UseFactory(AnrokIntegrationFactory::class)]
class AnrokIntegration extends Integration
{
    use HasFactory;

    /** Rails: secrets_accessors :connection_id. */
    public function connectionId(): ?string
    {
        return $this->getFromSecrets('connection_id');
    }

    /** Rails: secrets_accessors :api_key. */
    public function apiKey(): ?string
    {
        return $this->getFromSecrets('api_key');
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::ANROK_TYPE);
        });
    }
}
