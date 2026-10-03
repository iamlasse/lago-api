<?php

declare(strict_types=1);

namespace App\Expression;

/** Port of `Expression::EventAttribute(...)` — an event attribute access. */
final class EventAttributeNode implements Expression
{
    public function __construct(
        public readonly EventAttributeKind $kind,
        /** Property name when $kind is Properties, e.g. "seat_count". */
        public readonly ?string $propertyName = null,
    ) {}
}
