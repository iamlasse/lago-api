<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Quote;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::QuoteSerializer (app/serializers/v1/quote_serializer.rb)
 * — the quote header with the current (latest) version embedded.
 */
class QuoteSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var Quote $quote */
        $quote = $this->model;

        $payload = [
            'lago_id' => $quote->id,
            'number' => $quote->number,
            'order_type' => $quote->order_type,
            'lago_customer_id' => $quote->customer_id,
            'lago_subscription_id' => $quote->subscription_id,
            'lago_organization_id' => $quote->organization_id,
            'created_at' => $this->serializeDatetime($quote->created_at),
            'updated_at' => $this->serializeDatetime($quote->updated_at),
            'current_version' => $this->currentVersion($quote),
        ];

        if ($this->include('owners')) {
            $payload['owners'] = $this->owners($quote);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function currentVersion(Quote $quote): ?array
    {
        $version = $quote->currentVersion()->first();

        if ($version === null) {
            return null;
        }

        return (new QuoteVersionSerializer($version))->serialize();
    }

    /**
     * @return list<array{lago_id: string, email: ?string}>
     */
    protected function owners(Quote $quote): array
    {
        return $quote->owners()
            ->orderBy('quote_owners.id')
            ->get()
            ->map(fn (mixed $owner): array => [
                'lago_id' => $owner->id,
                'email' => $owner->email,
            ])
            ->all();
    }
}
