<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Enums\WebhookStatus;
use App\Models\Webhook as WebhookModel;

/**
 * Field resolvers for the frozen SDL's `Webhook` type (port of Rails'
 * Types::Webhooks::Object).
 */
class Webhook
{
    /** Rails: the status enum name — the column stores the integer position. */
    public function status(WebhookModel $root): ?string
    {
        // The current attribute (a retry flips the in-memory row to
        // pending; the raw original would still carry the old value).
        $raw = $root->status;

        if ($raw instanceof WebhookStatus) {
            $raw = $raw->value;
        }

        return $raw === null ? null : WebhookStatus::options()[(int) $raw] ?? null;
    }

    /** Rails: object.payload&.to_json. */
    public function payload(WebhookModel $root): ?string
    {
        $payload = $root->payload;

        if ($payload === null) {
            return null;
        }

        return is_string($payload) ? $payload : json_encode($payload);
    }
}
