<?php

declare(strict_types=1);

namespace App\Serializers\V1\Concerns;

use Carbon\CarbonInterface;

/**
 * Shared datetime formatting for the V1 serializer ports — Rails serializers
 * emit `model.created_at.iso8601` (UTC, "Z" suffix).
 */
trait FormatsDatetime
{
    protected function serializeDatetime(?CarbonInterface $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return $datetime->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
