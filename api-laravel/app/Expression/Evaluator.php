<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * Port of the gem's evaluation semantics (expression-core/src/evaluate.rs):
 *   - arithmetic operators require decimals on both sides (a String raises
 *     the ExpectedDecimal error);
 *   - event properties parse numeric strings into Numbers, anything else
 *     stays a String, and missing properties raise MissingVariable;
 *   - round/ceil/floor use BigDecimal rounding (half-up / ceiling / floor)
 *     with an optional digit argument truncated to an integer;
 *   - concat renders numbers scale-preservingly and concatenates strings;
 *   - least keeps the first of equal minima, greatest the last of equal
 *     maxima (Rust's iterator min/max tie-breaking).
 */
final class Evaluator
{
    public function evaluate(Expression $expression, ExpressionEvent $event): ExpressionValue
    {
        if ($expression instanceof EventAttributeNode) {
            return $this->evaluateEventAttribute($expression, $event);
        }

        if ($expression instanceof FunctionNode) {
            return $this->evaluateFunction($expression, $event);
        }

        if ($expression instanceof NumberNode) {
            return ExpressionValue::number($expression->value);
        }

        if ($expression instanceof StringNode) {
            return ExpressionValue::string($expression->value);
        }

        if ($expression instanceof UnaryMinusNode) {
            return ExpressionValue::number(
                Decimal::negate($this->toDecimal($this->evaluate($expression->inner, $event))),
            );
        }

        if ($expression instanceof BinaryOperationNode) {
            return $this->evaluateBinaryOperation($expression, $event);
        }

        throw new \LogicException('Unknown expression node '.get_class($expression));
    }

    private function evaluateEventAttribute(EventAttributeNode $node, ExpressionEvent $event): ExpressionValue
    {
        return match ($node->kind) {
            EventAttributeKind::Code => ExpressionValue::string($event->code),
            EventAttributeKind::Timestamp => ExpressionValue::number((string) $event->timestamp),
            EventAttributeKind::Properties => $event->property((string) $node->propertyName),
        };
    }

    private function evaluateBinaryOperation(BinaryOperationNode $node, ExpressionEvent $event): ExpressionValue
    {
        $lhs = $this->toDecimal($this->evaluate($node->lhs, $event));
        $rhs = $this->toDecimal($this->evaluate($node->rhs, $event));

        $result = match ($node->operator) {
            BinaryOperator::Add => Decimal::add($lhs, $rhs),
            BinaryOperator::Subtract => Decimal::subtract($lhs, $rhs),
            BinaryOperator::Multiply => Decimal::multiply($lhs, $rhs),
            // The gem panics on division by zero (surfaced through the ruby
            // extension as a RuntimeError); we raise the evaluation error.
            BinaryOperator::Divide => $this->divideOrThrow($lhs, $rhs),
        };

        return ExpressionValue::number($result);
    }

    private function evaluateFunction(FunctionNode $node, ExpressionEvent $event): ExpressionValue
    {
        return match ($node->name) {
            FunctionName::Concat => ExpressionValue::string($this->evaluateConcat($node, $event)),
            FunctionName::Round => $this->evaluateRounding($node, $event, RoundingMode::HalfUp),
            FunctionName::Ceil => $this->evaluateRounding($node, $event, RoundingMode::Ceiling),
            FunctionName::Floor => $this->evaluateRounding($node, $event, RoundingMode::Floor),
            FunctionName::Least => ExpressionValue::number($this->evaluateExtremum($node, $event, least: true)),
            FunctionName::Greatest => ExpressionValue::number($this->evaluateExtremum($node, $event, least: false)),
        };
    }

    private function evaluateConcat(FunctionNode $node, ExpressionEvent $event): string
    {
        $parts = [];

        foreach ($node->arguments as $argument) {
            $parts[] = $this->evaluate($argument, $event)->display();
        }

        return implode('', $parts);
    }

    private function evaluateRounding(FunctionNode $node, ExpressionEvent $event, RoundingMode $mode): ExpressionValue
    {
        $value = $this->toDecimal($this->evaluate($node->arguments[0], $event));

        $digits = 0;

        if ($node->digits !== null) {
            // The gem truncates the digit expression toward zero via
            // BigDecimal#to_i64 (round(2.4, 1.5) rounds at 1 digit); an
            // out-of-i64-range value raises ExpectedDecimal.
            $digits = Decimal::truncateToInteger(
                $this->toDecimal($this->evaluate($node->digits, $event)),
            );

            if ($digits === null) {
                throw new ExpressionEvaluationException('Expected a decimal');
            }
        }

        return ExpressionValue::number($this->withScaleRoundOrThrow($value, $digits, $mode));
    }

    private function evaluateExtremum(FunctionNode $node, ExpressionEvent $event, bool $least): string
    {
        if ($node->arguments === []) {
            throw new ExpressionEvaluationException('Expected non-empty argument list');
        }

        $best = $this->toDecimal($this->evaluate($node->arguments[0], $event));

        foreach (array_slice($node->arguments, 1) as $argument) {
            $candidate = $this->toDecimal($this->evaluate($argument, $event));
            $comparison = bccomp($candidate, $best, max(Decimal::scaleOf($candidate), Decimal::scaleOf($best)));

            $takesOver = $least
                ? $comparison === -1
                : $comparison >= 0;

            if ($takesOver) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function toDecimal(ExpressionValue $value): string
    {
        return $value->decimal();
    }

    private function divideOrThrow(string $lhs, string $rhs): string
    {
        try {
            return Decimal::divide($lhs, $rhs);
        } catch (\InvalidArgumentException $exception) {
            throw new ExpressionEvaluationException($exception->getMessage(), previous: $exception);
        }
    }

    private function withScaleRoundOrThrow(string $value, int $digits, RoundingMode $mode): string
    {
        try {
            return Decimal::withScaleRound($value, $digits, $mode);
        } catch (\InvalidArgumentException $exception) {
            throw new ExpressionEvaluationException($exception->getMessage(), previous: $exception);
        }
    }
}
