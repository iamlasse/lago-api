<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use App\Services\Failures\ValidationFailure;
use App\Services\BillableMetrics\EvaluateExpressionService;

uses()->group('ledger:svc:BillableMetrics.EvaluateExpressionService');

/**
 * Unit contract of the evaluate_expression service port (the Rails request
 * spec scenarios live in tests/Feature/Api/V1/BillableMetricsControllerTest.php).
 */
function callEvaluateExpressionService(array $args): App\Services\BaseResult
{
    return EvaluateExpressionService::call(...$args);
}

it('requires the expression', function (): void {
    Date::setTestNow();

    $result = callEvaluateExpressionService(['expression' => '', 'event' => []]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['expression' => ['value_is_mandatory']]);
});

it('requires the expression even when it is whitespace only', function (): void {
    // Rails' blank? treats whitespace-only strings as blank.
    $result = callEvaluateExpressionService(['expression' => '   ', 'event' => []]);

    expect($result->getError()->messages)->toBe(['expression' => ['value_is_mandatory']]);
});

it('answers invalid_expression when the expression does not parse', function (): void {
    $result = callEvaluateExpressionService(['expression' => '1 +', 'event' => []]);

    expect($result->getError()->messages)->toBe(['expression' => ['invalid_expression']]);
});

it('evaluates the expression against the event', function (): void {
    $result = callEvaluateExpressionService([
        'expression' => 'round(event.properties.value)',
        'event' => [
            'code' => 'bm_code',
            'timestamp' => 1234,
            'properties' => ['value' => '2.4'],
        ],
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->evaluation_result)->toBe('2.0');
});

it('passes the evaluated number through the Rails BigDecimal JSON format', function (): void {
    $result = callEvaluateExpressionService([
        'expression' => '(123 - event.properties.value) / 10',
        'event' => ['properties' => ['value' => '1.23']],
    ]);

    expect($result->evaluation_result)->toBe('12.177');
});

it('evaluates to a bare string when the expression is textual', function (): void {
    $result = callEvaluateExpressionService([
        'expression' => "concat('test', '-', event.properties.value)",
        'event' => ['properties' => ['value' => 'suffix']],
    ]);

    expect($result->evaluation_result)->toBe('test-suffix');
});

it('answers invalid_event when evaluation raises', function (): void {
    $result = callEvaluateExpressionService([
        'expression' => 'event.properties.does_not_exists',
        'event' => ['properties' => []],
    ]);

    expect($result->getError()->messages)->toBe(['event' => ['invalid_event']]);
});

it('answers invalid_event when a non-decimal feeds arithmetic', function (): void {
    $result = callEvaluateExpressionService([
        'expression' => 'event.properties.value + 1',
        'event' => ['properties' => ['value' => 'abc']],
    ]);

    expect($result->getError()->messages)->toBe(['event' => ['invalid_event']]);
});

it('defaults the timestamp to the current time', function (): void {
    Date::setTestNow();

    $result = callEvaluateExpressionService([
        'expression' => 'event.timestamp',
        'event' => ['code' => 'bm_code'],
    ]);

    expect($result->evaluation_result)->toBe(now()->getTimestamp().'.0');
});

it('defaults the code to an empty string', function (): void {
    $result = callEvaluateExpressionService(['expression' => 'event.code', 'event' => []]);

    expect($result->evaluation_result)->toBe('');
});

it('accepts a null event like Rails nil event', function (): void {
    $result = callEvaluateExpressionService(['expression' => "'test'", 'event' => null]);

    expect($result->success())->toBeTrue()
        ->and($result->evaluation_result)->toBe('test');
});
