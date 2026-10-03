<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Port of the gem's EventAttribute enum (event.code, event.timestamp,
 * event.properties.<name>).
 */
enum EventAttributeKind
{
    case Code;
    case Timestamp;
    case Properties;
}
