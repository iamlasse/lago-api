<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Port of the gem's Event (expression-core/src/event.rs): the code, the
 * timestamp, and the free-form properties map an expression may reference
 * via event.code / event.timestamp / event.properties.<name>.
 *
 * Properties are raw scalars, exactly like the JSON the Rails services feed
 * into Lago::Event.new — numbers become Numbers (via their string form, the
 * way the ruby extension does it) and anything else stays a string.
 */
final class ExpressionEvent
{
    /** @param array<string, mixed> $properties */
    public function __construct(
        public readonly string $code = '',
        public readonly int $timestamp = 0,
        public readonly array $properties = [],
    ) {}

    /**
     * Builds an event from a raw event payload, the way Rails'
     * Events::CalculateExpressionService does
     * (`Lago::Event.new(event.code, event.timestamp.to_i, event.properties)`).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $timestamp = $payload['timestamp'] ?? 0;

        return new self(
            code: (string) ($payload['code'] ?? ''),
            timestamp: is_numeric($timestamp) ? (int) $timestamp : 0,
            properties: is_array($payload['properties'] ?? null) ? $payload['properties'] : [],
        );
    }

    /**
     * Port of EventAttribute evaluation's PropertyValue -> ExpressionValue
     * conversion: a string that parses as a decimal becomes a Number, every
     * other string stays a String, and numbers arrive through their string
     * form (the ruby extension formats numbers before BigDecimal parsing).
     *
     * @throws ExpressionEvaluationException when the property is missing
     */
    public function property(string $name): ExpressionValue
    {
        if (! array_key_exists($name, $this->properties)) {
            throw new ExpressionEvaluationException("Variable: {$name} not found");
        }

        $value = $this->properties[$name];

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            $value = json_encode($value);
        }

        $decimal = Decimal::parse($value);

        return $decimal === null ? ExpressionValue::string($value) : ExpressionValue::number($decimal);
    }
}
