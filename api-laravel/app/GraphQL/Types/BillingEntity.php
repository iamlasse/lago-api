<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\GraphQL\Support\TimezoneWire;

/**
 * Field resolvers for the frozen SDL's `BillingEntity` type — currently only
 * the timezone wire conversion; plain columns resolve through Lighthouse's
 * default snake_case attribute lookup.
 */
class BillingEntity
{
    /** The timezone column holds the IANA identifier; the wire carries TZ_*. */
    public function timezone(mixed $root): ?string
    {
        return TimezoneWire::toWire($root->timezone);
    }
}
