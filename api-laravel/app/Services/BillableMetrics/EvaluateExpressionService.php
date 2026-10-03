<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics;

use App\Expression\Parser;
use App\Services\BaseResult;
use App\Expression\Evaluator;
use App\Services\BaseService;
use App\Expression\ExpressionEvent;
use App\Expression\ExpressionValue;
use App\Expression\ExpressionParseException;
use App\Expression\ExpressionEvaluationException;

/**
 * Port of Rails' BillableMetrics::EvaluateExpressionService
 * (app/services/billable_metrics/evaluate_expression_service.rb) on top of
 * the App\Expression parser port (the lago-expression gem).
 */
class EvaluateExpressionService extends BaseService
{
    public function __construct(
        private readonly ?string $expression,
        private readonly ?array $event = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('evaluation_result');

        if ($this->expression === null || mb_trim($this->expression) === '') {
            return $result->singleValidationFailure('value_is_mandatory', 'expression');
        }

        // Lago::ExpressionParser.validate — a parse error answers the
        // invalid_expression envelope.
        try {
            $expression = Parser::parse($this->expression);
        } catch (ExpressionParseException) {
            return $result->singleValidationFailure('invalid_expression', 'expression');
        }

        // Lago::Event.new(event["code"].to_s, (event["timestamp"] ||
        // Time.current).to_i, event["properties"]&.transform_values(&:to_s)).
        $payload = $this->event ?? [];

        $properties = [];

        foreach ((array) ($payload['properties'] ?? []) as $key => $value) {
            // Ruby's Object#to_s: scalars stringify directly (nil -> "");
            // for the array/object shapes Ruby renders an inspect string,
            // where JSON is the closest sane PHP stand-in.
            $properties[(string) $key] = match (true) {
                $value === null => '',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value),
            };
        }

        $evaluationEvent = new ExpressionEvent(
            code: (string) ($payload['code'] ?? ''),
            timestamp: (int) ($payload['timestamp'] ?? now()->getTimestamp()),
            properties: $properties,
        );

        // A RuntimeError during evaluation (missing variable, non-decimal
        // operand, division by zero) answers the invalid_event envelope.
        try {
            $value = (new Evaluator)->evaluate($expression, $evaluationEvent);
        } catch (ExpressionEvaluationException) {
            return $result->singleValidationFailure('invalid_event', 'event');
        }

        $result->evaluation_result = $this->serializedValue($value);

        return $result;
    }

    /**
     * Rails keeps a Ruby BigDecimal (or String) on the result and the JSON
     * layer renders BigDecimals via BigDecimal#to_s('F').
     */
    private function serializedValue(ExpressionValue $value): string
    {
        return $value->toRailsString();
    }
}
