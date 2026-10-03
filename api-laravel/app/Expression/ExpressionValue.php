<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Port of the gem's ExpressionValue (expression-core/src/evaluate.rs).
 *
 * A Number carries its decimal string with the intrinsic scale of the
 * literal/property it came from; String carries the raw text.
 */
final class ExpressionValue
{
    private function __construct(
        public readonly ExpressionValueKind $kind,
        public readonly string $value,
    ) {}

    /** @param string $decimal canonical decimal string */
    public static function number(string $decimal): self
    {
        return new self(ExpressionValueKind::Number, $decimal);
    }

    public static function string(string $value): self
    {
        return new self(ExpressionValueKind::String, $value);
    }

    public function isNumber(): bool
    {
        return $this->kind === ExpressionValueKind::Number;
    }

    /**
     * The decimal value of a Number, mirroring ExpressionValue::to_decimal
     * (a String raises the gem's ExpectedDecimal error).
     *
     * @throws ExpressionEvaluationException when this is a String
     */
    public function decimal(): string
    {
        if ($this->kind === ExpressionValueKind::String) {
            throw new ExpressionEvaluationException('Expected a decimal');
        }

        return $this->value;
    }

    /**
     * Port of Rust's Display (used by concat): scale-preserving, zero
     * collapses to "0" ("2.40" -> "2.40", "2.40 - 2.40" -> "0").
     */
    public function display(): string
    {
        return $this->kind === ExpressionValueKind::Number
            ? Decimal::display($this->value)
            : $this->value;
    }

    /**
     * How the value is serialized in the Rails API (the JSON encoding of a
     * Ruby BigDecimal, i.e. BigDecimal#to_s('F'): "2" -> "2.0", "2.350" ->
     * "2.35"); strings pass through untouched.
     */
    public function toRailsString(): string
    {
        return $this->kind === ExpressionValueKind::Number
            ? Decimal::toRailsFormat($this->value)
            : $this->value;
    }
}
