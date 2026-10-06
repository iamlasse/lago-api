<?php

declare(strict_types=1);

namespace App\Models\IntegrationCollectionMappings;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\AvalaraCollectionMappingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' IntegrationCollectionMappings::AvalaraCollectionMapping
 * (app/models/integration_collection_mappings/avalara_collection_mapping.rb)
 * — an empty STI subclass.
 */
#[UseFactory(AvalaraCollectionMappingFactory::class)]
class AvalaraCollectionMapping extends BaseCollectionMapping
{
    use HasFactory;

    public const TYPE = BaseCollectionMapping::AVALARA_TYPE;

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::TYPE);
        });

        // Rails STI: the stored type column is set on create.
        static::creating(function (self $mapping): void {
            $mapping->type = self::TYPE;
        });
    }
}
