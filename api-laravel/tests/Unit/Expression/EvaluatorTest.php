<?php

declare(strict_types=1);

uses()->group('expression');

use App\Expression\Parser;
use App\Expression\Decimal;
use App\Expression\Evaluator;
use App\Expression\NumberNode;
use App\Expression\ExpressionEvent;
use App\Expression\ExpressionValue;
use App\Expression\EventAttributeKind;
use App\Expression\EventAttributeNode;

/**
 * Port of the gem's evaluation spec corpus:
 *   - expression-core/src/evaluate.rs #[cfg(test)] (test_evaluate_*)
 *   - expression-ruby/spec/lago-expression/expression_spec.rb
 * plus the semantics pinned empirically against the compiled gem (v0.2.0).
 */
function evaluateExpression(string $input, array $properties = [], string $code = 'c', int $timestamp = 1): ExpressionValue
{
    $event = new ExpressionEvent(code: $code, timestamp: $timestamp, properties: $properties);

    return (new Evaluator)->evaluate(Parser::parse($input), $event);
}

function expectEvaluation(string $input, string $expectedValue, array $properties = []): void
{
    expect(evaluateExpression($input, $properties)->display())->toBe($expectedValue);
}

// -- Port of evaluate.rs ------------------------------------------------------

it('evaluates a decimal', function (): void {
    expect((new Evaluator)->evaluate(new NumberNode('123'), new ExpressionEvent)->display())->toBe('123');
});

it('evaluates the event code attribute', function (): void {
    $value = (new Evaluator)->evaluate(
        new EventAttributeNode(EventAttributeKind::Code),
        new ExpressionEvent(code: 'result_code'),
    );

    expect($value)->toEqual(ExpressionValue::string('result_code'));
});

it('evaluates the event timestamp attribute', function (): void {
    $value = (new Evaluator)->evaluate(
        new EventAttributeNode(EventAttributeKind::Timestamp),
        new ExpressionEvent(timestamp: 1234),
    );

    expect($value->display())->toBe('1234');
});

it('evaluates a decimal event property', function (): void {
    expectEvaluation('event.properties.bar', '123', ['bar' => '123']);
});

it('evaluates a non-decimal event property as a string', function (): void {
    expect(evaluateExpression('event.properties.bar', ['bar' => 'foo']))
        ->toEqual(ExpressionValue::string('foo'));
});

it('evaluates a string', function (): void {
    expect(evaluateExpression("'bar'"))->toEqual(ExpressionValue::string('bar'));
});

it('evaluates the four binary operations', function (): void {
    expectEvaluation('2 + 4', '6');
    expectEvaluation('2 - 4', '-2');
    expectEvaluation('2 * 4', '8');
    expectEvaluation('4 / 2', '2');
});

it('evaluates a unary minus', function (): void {
    expectEvaluation('-12', '-12');
});

it('evaluates round', function (): void {
    expectEvaluation('round(12.5)', '13');
    expectEvaluation('round(12.345, 2)', '12.35');
});

it('evaluates ceil', function (): void {
    expectEvaluation('ceil(12.3)', '13');
    expectEvaluation('ceil(12.351, 1)', '12.4');
});

it('evaluates floor', function (): void {
    expectEvaluation('floor(12.3)', '12');
    expectEvaluation('floor(12.351, 1)', '12.3');
});

it('evaluates concat', function (): void {
    expect(evaluateExpression("concat('test', '-', '123')"))
        ->toEqual(ExpressionValue::string('test-123'));
});

it('evaluates nested functions', function (): void {
    expect(evaluateExpression("concat('test', '-', round(123))"))
        ->toEqual(ExpressionValue::string('test-123'));
});

// -- Port of expression_spec.rb ------------------------------------------------

$corpusEvent = [
    'property_1' => '1.23',
    'dummy' => '1', // the ruby spec passes an object; the extension stringifies it
    'decimal_property' => '2.3',
    'property_2' => 'test',
    'property_3' => '12.34',
];

it('evaluates a simple math expression to a number', function () use ($corpusEvent): void {
    $value = evaluateExpression('1 + 3', $corpusEvent);

    expect($value->display())->toBe('4')->and($value->isNumber())->toBeTrue();
});

it('raises when a property does not exist', function () use ($corpusEvent): void {
    expect(fn (): ExpressionValue => evaluateExpression('event.properties.does_not_exists', $corpusEvent))
        ->toThrow(App\Expression\ExpressionEvaluationException::class, 'Variable: does_not_exists not found');
});

it('evaluates a simple string expression', function () use ($corpusEvent): void {
    $value = evaluateExpression("'test'", $corpusEvent);

    expect($value->display())->toBe('test')->and($value->isNumber())->toBeFalse();
});

it('evaluates math with a decimal value from the event', function () use ($corpusEvent): void {
    expectEvaluation('(123 - event.properties.property_1) / 10', '12.177', $corpusEvent);
});

it('concatenates a decimal value from the event', function () use ($corpusEvent): void {
    expect(evaluateExpression("CONCAT(event.properties.property_1, 'test')", $corpusEvent)->display())
        ->toBe('1.23test');
});

it('adds two decimal values from the event', function () use ($corpusEvent): void {
    expectEvaluation('event.properties.property_1 + event.properties.decimal_property', '3.53', $corpusEvent);
});

it('evaluates a property holding a non-number', function () use ($corpusEvent): void {
    expect(evaluateExpression('event.properties.dummy', $corpusEvent)->display())->toBe('1');
});

it('concatenates a property with a suffix', function () use ($corpusEvent): void {
    expect(evaluateExpression("concat(event.properties.property_2, '-', 'suffix')", $corpusEvent)->display())
        ->toBe('test-suffix');
});

it('rounds a property with negative digits', function () use ($corpusEvent): void {
    expectEvaluation('round(event.properties.property_3, -1)', '10', $corpusEvent);
});

it('evaluates least and greatest against a property', function () use ($corpusEvent): void {
    expectEvaluation('least(event.properties.property_3, 5.0)', '5.0', $corpusEvent);
    expectEvaluation('least(event.properties.property_3, 15.0)', '12.34', $corpusEvent);
    expectEvaluation('greatest(event.properties.property_3, 5.0)', '12.34', $corpusEvent);
    expectEvaluation('greatest(event.properties.property_3, 15.0)', '15.0', $corpusEvent);
});

// -- Semantics pinned empirically against the compiled gem ----------------------

it('binds the unary minus to the atom, not the whole expression', function (): void {
    expectEvaluation('-2 + 3', '1');
    expectEvaluation('1 - -2', '3');
    expectEvaluation('-(2 + 3)', '-5');
    expectEvaluation('- 5', '-5');
});

it('tracks decimal scale like rust BigDecimal', function (): void {
    expectEvaluation('1.5 * 2', '3.0');
    expectEvaluation('1.5 + 2', '3.5');
    expectEvaluation('2.40 + 1', '3.40');
    expectEvaluation('-2.40', '-2.40');
    expectEvaluation('1.10', '1.10');
    expectEvaluation('2.40 - 2.40', '0');
    expectEvaluation('floor(-0.0)', '0');
});

it('renders numbers scale-preservingly inside concat', function (): void {
    expect(evaluateExpression("concat(2.40, 'x')")->display())->toBe('2.40x');
    expect(evaluateExpression('concat(event.properties.s, 1)', ['s' => 'abc'])->display())->toBe('abc1');
});

it('breaks ties in least by keeping the first and in greatest the last argument', function (): void {
    expectEvaluation('least(2.40, 2.4)', '2.40');
    expectEvaluation('greatest(2.40, 2.4)', '2.4');
});

it('rounds division quotients to 100 significant digits', function (): void {
    expectEvaluation('2/4', '0.5');
    expectEvaluation('10.5 / 7', '1.5');
    expectEvaluation('1/8', '0.125');
    expectEvaluation('1/3', '0.'.str_repeat('3', 100));
    expectEvaluation('2/3', '0.'.str_repeat('6', 99).'7');
    expectEvaluation('123/7', '17.'.str_repeat('571428', 16).'57');
});

it('rounds negative division quotients away from zero', function (): void {
    expectEvaluation('-6/4', '-1.5');
    expectEvaluation('1 / -3', '-0.'.str_repeat('3', 100));
    expectEvaluation('-2/3', '-0.'.str_repeat('6', 99).'7');
    expectEvaluation('2 / -3', '-0.'.str_repeat('6', 99).'7');
    expectEvaluation('0/3', '0');
});

it('raises on division by zero like the gem raises a runtime error', function (): void {
    expect(fn (): ExpressionValue => evaluateExpression('1/0'))
        ->toThrow(App\Expression\ExpressionEvaluationException::class, 'divided by zero');
});

it('raises when an operand is not a decimal', function (): void {
    expect(fn (): ExpressionValue => evaluateExpression('event.properties.s + 1', ['s' => 'abc']))
        ->toThrow(App\Expression\ExpressionEvaluationException::class, 'Expected a decimal');
});

it('truncates the rounding digit argument toward zero', function (): void {
    // BigDecimal#to_i64 truncates: 1.5 digits means one digit.
    expectEvaluation('round(2.4, 1.5)', '2.4');
});

it('rounds halves away from zero', function (): void {
    expectEvaluation('round(2.5)', '3');
    expectEvaluation('round(-2.5)', '-3');
    expectEvaluation('ceil(-1.5)', '-1');
    expectEvaluation('floor(-1.5)', '-2');
});

it('rounds whole powers of ten with negative digit arguments', function (): void {
    expectEvaluation('round(12.34, -1)', '10');
    expectEvaluation('round(125, -2)', '100');
    expectEvaluation('ceil(12.351, 1)', '12.4');
    expectEvaluation('floor(12.351, 1)', '12.3');
});

it('keeps the requested scale when rounding with positive digits', function (): void {
    expectEvaluation('round(2.40, 1)', '2.4');
    expectEvaluation('round(2.4, 5)', '2.40000');
});

it('accepts the three function name spellings only', function (): void {
    expectEvaluation('Ceil(1.2)', '2');
    expectEvaluation('CEIL(1.2)', '2');
    expect(fn (): ExpressionValue => evaluateExpression('cEil(1.2)'))
        ->toThrow(App\Expression\ExpressionParseException::class);
});

it('exposes the event timestamp and code', function (): void {
    expectEvaluation('event.timestamp + 1', '2');
    expect(evaluateExpression('event.code', code: 'bm_code')->display())->toBe('bm_code');
});

// -- Serialization ----------------------------------------------------------------

it('serializes numbers the way Rails renders BigDecimals in JSON', function (): void {
    expect(Decimal::toRailsFormat('2'))->toBe('2.0')
        ->and(Decimal::toRailsFormat('2.40'))->toBe('2.4')
        ->and(Decimal::toRailsFormat('2.350'))->toBe('2.35')
        ->and(Decimal::toRailsFormat('0'))->toBe('0.0')
        ->and(Decimal::toRailsFormat('-0.00'))->toBe('0.0')
        ->and(Decimal::toRailsFormat('10'))->toBe('10.0')
        ->and(Decimal::toRailsFormat('0.001'))->toBe('0.001')
        ->and(Decimal::toRailsFormat('-12.50'))->toBe('-12.5');
});

it('builds an event from a raw payload', function (): void {
    $event = ExpressionEvent::fromPayload([
        'code' => 'bm_code',
        'timestamp' => '123124123123',
        'properties' => ['number' => 300, 'text' => 'text_value'],
    ]);

    expect($event->code)->toBe('bm_code')
        ->and($event->timestamp)->toBe(123124123123)
        ->and($event->property('number')->display())->toBe('300')
        ->and($event->property('text'))->toEqual(ExpressionValue::string('text_value'));
});
