<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Event;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::EventSerializer
 * (app/serializers/v1/event_serializer.rb).
 */
class EventSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var Event $event */
        $event = $this->model;

        $payload = [
            'lago_id' => $event->id,
            'transaction_id' => $event->transaction_id,
            'lago_customer_id' => $event->customer_id,
            'code' => $event->code,
            // Rails: model.timestamp.iso8601(3) — UTC with millisecond precision.
            'timestamp' => $event->timestamp?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            // Rails: model.precise_total_amount_cents&.to_s — BigDecimal#to_s
            // renders scientific notation ("0.12345e3"), reproduced verbatim.
            'precise_total_amount_cents' => $event->precise_total_amount_cents === null
                ? null
                : self::bigDecimalToS((string) $event->precise_total_amount_cents),
            'properties' => $event->properties,
            'lago_subscription_id' => $event->subscription_id,
            'external_subscription_id' => $event->external_subscription_id,
            'created_at' => $this->serializeDatetime($event->created_at),
        ];

        // CONTRACT NOTE: the captured batch response (golden
        // events_ingestion/7.json) carries `updated_at` on every event, while
        // the single create / show / index goldens (1/4/8/9.json) do not —
        // and the checked-out Rails snapshot's V1::EventSerializer
        // (app/serializers/v1/event_serializer.rb, spec confirmed) emits no
        // `updated_at` at all. The goldens are truth, so the batch endpoint
        // opts into the field via the `with_updated_at` option; every other
        // path keeps the snapshot shape (key absent, not null).
        if ((bool) ($this->options['with_updated_at'] ?? false)) {
            $payload['updated_at'] = $this->serializeDatetime($event->updated_at);
        }

        return $payload;
    }

    /**
     * Port of Ruby's BigDecimal#to_s (no argument): scientific notation with
     * a "0." mantissa ("123.45" -> "0.12345e3", "0" -> "0.0", "0.001" ->
     * "0.1e-2").
     */
    private static function bigDecimalToS(string $numeric): string
    {
        $negative = str_starts_with($numeric, '-');
        $digits = str_replace('.', '', mb_ltrim($numeric, '+-'));
        $dotIndex = (int) (mb_strpos(mb_ltrim($numeric, '+-'), '.') ?: mb_strlen(mb_ltrim($numeric, '+-')));

        $significant = mb_ltrim($digits, '0');
        if ($significant === '' || mb_rtrim($significant, '0') === '') {
            return '0.0';
        }

        $mantissa = mb_rtrim($significant, '0');
        $firstSignificantIndex = mb_strlen($digits) - mb_strlen($significant);
        $exponent = $dotIndex - $firstSignificantIndex;

        return ($negative ? '-' : '').'0.'.$mantissa.'e'.$exponent;
    }
}
