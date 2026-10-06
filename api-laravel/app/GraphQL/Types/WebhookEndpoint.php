<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\WebhookEndpoint as WebhookEndpointModel;

/**
 * Field resolvers for the frozen SDL's `WebhookEndpoint` type (port of
 * Rails' Types::WebhookEndpoints::Object).
 */
class WebhookEndpoint
{
    /** Rails: the signature_algo enum name (the model resolves the stored position). */
    public function signatureAlgo(WebhookEndpointModel $root): ?string
    {
        return $root->signatureAlgoValue();
    }
}
