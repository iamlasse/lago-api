<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\QuoteVersion;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::QuoteVersionSerializer
 * (app/serializers/v1/quote_version_serializer.rb).
 *
 * content/billing_items are heavy blobs: rendered only for single-resource
 * responses, never in list/embed payloads.
 *
 * share_token is intentionally omitted from the REST API: it is a bearer
 * capability with no consumer yet, disclosed only through a purpose-built
 * share endpoint when the sharing feature lands.
 */
class QuoteVersionSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var QuoteVersion $quoteVersion */
        $quoteVersion = $this->model;

        $payload = [
            'lago_id' => $quoteVersion->id,
            'lago_quote_id' => $quoteVersion->quote_id,
            'lago_organization_id' => $quoteVersion->organization_id,
            'version' => $quoteVersion->version(),
            'status' => $quoteVersion->status,
            'currency' => $quoteVersion->currency,
            'billing_entity_code' => $quoteVersion->resolvedBillingEntity()?->code,
            'void_reason' => $quoteVersion->void_reason,
            'approved_at' => $this->serializeDatetime($quoteVersion->approved_at),
            'voided_at' => $this->serializeDatetime($quoteVersion->voided_at),
            'created_at' => $this->serializeDatetime($quoteVersion->created_at),
            'updated_at' => $this->serializeDatetime($quoteVersion->updated_at),
        ];

        if ($this->include('content')) {
            $payload['content'] = $quoteVersion->content;
        }

        if ($this->include('billing_items')) {
            $payload['billing_items'] = $quoteVersion->billing_items;
        }

        return $payload;
    }
}
