<?php

declare(strict_types=1);

namespace App\Models\IntegrationMappings;

use Database\Factories\AnrokMappingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' IntegrationMappings::AnrokMapping (app/models/
 * integration_mappings/anrok_mapping.rb) — an empty STI subclass.
 */
#[UseFactory(AnrokMappingFactory::class)]
class AnrokMapping extends BaseMapping
{
    use HasFactory;

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::ANROK_TYPE);
        });

        // Rails STI: the stored type column is set on create.
        static::creating(function (self $mapping): void {
            $mapping->type = self::ANROK_TYPE;
        });
    }
}
