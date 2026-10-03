<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Expression\Parser;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Expression\Evaluator;
use App\Services\BaseService;
use App\Expression\ExpressionEvent;
use App\Expression\ExpressionParseException;
use App\Expression\ExpressionEvaluationException;
use App\Services\BillableMetrics\ExpressionCacheService;

/**
 * Port of Rails' Events::CalculateExpressionService
 * (app/services/events/calculate_expression_service.rb) — the third
 * integration point of the expression parser port (App\Expression; see
 * Parser's class docblock).
 *
 * For a billable metric configured with an `expression` (custom
 * aggregation), evaluates the expression against the incoming event and
 * writes the evaluated value into the stored event properties under the
 * metric's field_name.
 *
 * Rails assigns the gem's ExpressionValue OBJECT into the properties hash;
 * its JSON form in the jsonb column is the Rails number format
 * ("3.0" for 1 + 2 — see ExpressionValue::toRailsString), which the Rails
 * controller spec asserts.
 */
class CalculateExpressionService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly Event $event,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('event');
        $result->event = $this->event;

        [$fieldName, $expression] = ExpressionCacheService::call(
            $this->organization->id,
            (string) $this->event->code,
            function (): array {
                // Rails: organization.billable_metrics.with_expression.find_by(code:)
                $metric = $this->organization->billableMetrics()
                    ->whereNotNull('expression')
                    ->where('code', $this->event->code)
                    ->first();

                return [$metric?->field_name, $metric?->expression];
            },
        );
        if ($expression === null || mb_trim($expression) === '') {
            return $result;
        }

        // Lago::Event.new(event.code, event.timestamp.to_i, event.properties)
        $evaluationEvent = new ExpressionEvent(
            code: (string) $this->event->code,
            timestamp: (int) $this->event->timestamp?->getTimestamp(),
            properties: (array) ($this->event->properties ?? []),
        );

        // The expression can always be parsed, otherwise it would not be saved.
        // A RuntimeError (missing variable, non-decimal operand, ...) — or, in
        // principle, a parse failure — answers the service failure.
        try {
            $value = (new Evaluator)->evaluate(Parser::parse($expression), $evaluationEvent);
        } catch (ExpressionParseException|ExpressionEvaluationException $exception) {
            return $result->serviceFailure('expression_evaluation_failed', $exception->getMessage());
        }

        $properties = (array) ($this->event->properties ?? []);
        $properties[$fieldName] = $value->toRailsString();
        $this->event->properties = $properties;

        return $result;
    }
}
