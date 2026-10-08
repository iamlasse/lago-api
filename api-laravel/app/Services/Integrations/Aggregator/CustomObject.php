<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

/**
 * Port of the CustomObject data object nested in Rails'
 * Integrations::Aggregator::CustomObjectService
 * (`CustomObject = Data.define(:id, :object_type_id)`).
 */
final class CustomObject
{
    public function __construct(
        public readonly mixed $id,
        public readonly mixed $object_type_id,
    ) {}
}
