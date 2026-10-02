<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * webhook_endpoints.signature_algo — integer column, Rails enum order is the
 * stored value, 0-based (app/models/webhook_endpoint.rb `enum :signature_algo,
 * SIGNATURE_ALGOS`). Rails' default is :jwt (column default 0).
 */
enum WebhookEndpointSignatureAlgo: int
{
    case Jwt = 0;
    case Hmac = 1;

    /** @return list<string> Rails' SIGNATURE_ALGOS names, in order. */
    public static function options(): array
    {
        return ['jwt', 'hmac'];
    }

    /**
     * Rails assigns the enum NAME ("jwt" | …) and the column stores the
     * integer position; returns the position, or null when the name is not
     * one of the options.
     */
    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        $index = array_search(
            is_string($value) ? mb_strtolower($value) : $value,
            self::options(),
            true,
        );

        return $index === false ? null : $index;
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return self::options()[$this->value];
    }
}
