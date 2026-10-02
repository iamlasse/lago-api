<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * webhooks.status — integer column, Rails enum order is the stored value,
 * 0-based (app/models/webhook.rb `enum :status, STATUS`).
 */
enum WebhookStatus: int
{
    case Pending = 0;
    case Succeeded = 1;
    case Failed = 2;
    case Retrying = 3;

    /** @return list<string> Rails' Webhook::STATUS names, in order. */
    public static function options(): array
    {
        return ['pending', 'succeeded', 'failed', 'retrying'];
    }

    /**
     * Rails assigns the enum NAME ("pending" | …) and the column stores the
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
