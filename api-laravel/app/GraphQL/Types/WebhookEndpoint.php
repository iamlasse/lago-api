<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Enums\WebhookEndpointSignatureAlgo;
use App\Models\WebhookEndpoint as WebhookEndpointModel;

/**
 * Field resolvers for the frozen SDL's `WebhookEndpoint` type (port of
 * Rails' Types::WebhookEndpoints::Object).
 */
class WebhookEndpoint
{
    /** Rails: the signature_algo enum name — the column stores the integer position. */
    public function signatureAlgo(WebhookEndpointModel $root): ?string
    {
        $algo = $root->signatureAlgoValue();

        return $algo === null ? null : WebhookEndpointSignatureAlgo::options()[$algo] ?? null;
    }
}
