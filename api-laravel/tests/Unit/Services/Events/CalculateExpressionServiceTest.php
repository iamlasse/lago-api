<?php

declare(strict_types=1);

use App\Models\Event;
use App\Expression\Parser;
use App\Models\Organization;
use App\Expression\Evaluator;
use App\Expression\ExpressionEvent;
use App\Services\Failures\ServiceFailure;
use App\Services\Events\CalculateExpressionService;
use App\Services\BillableMetrics\ExpressionCacheService;

uses()->group('ledger:svc:Events.CalculateExpressionService');

/**
 * Port of Rails' spec/services/events/calculate_expression_service_spec.rb,
 * on top of the App\Expression parser port.
 */
function expressionEvent(Organization $organization, string $code, array $properties): Event
{
    $event = new Event();
    $event->organization_id = $organization->id;
    $event->code = $code;
    $event->timestamp = Illuminate\Support\Facades\Date::now();
    $event->properties = $properties;

    return $event;
}

it('returns the event untouched without an expression', function (): void {
    $organization = Organization::factory()->create();
    $event = expressionEvent($organization, 'no_expression_code', ['a' => '1']);

    $result = CalculateExpressionService::call(organization: $organization, event: $event);

    expect($result->success())->toBeTrue()
        ->and($result->event->properties)->toBe(['a' => '1']);
});

it('writes the evaluated expression under the field name', function (): void {
    $organization = Organization::factory()->create();
    App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'code' => 'expr_metric',
        'field_name' => 'result',
        'expression' => 'event.properties.left + event.properties.right',
    ]);

    $event = expressionEvent($organization, 'expr_metric', ['left' => '1', 'right' => '2']);
    $result = CalculateExpressionService::call(organization: $organization, event: $event);

    expect($result->success())->toBeTrue()
        // The gem's ExpressionValue renders as Rails' number format in the
        // jsonb properties (see the Rails controller spec: "3.0").
        ->and($result->event->properties['result'])->toBe('3.0')
        ->and($result->event->properties['left'])->toBe('1');
});

it('serves the expression from the cache', function (): void {
    $organization = Organization::factory()->create();
    App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'code' => 'cached_metric',
        'field_name' => 'result',
        'expression' => 'round(event.properties.v)',
    ]);

    $result = CalculateExpressionService::call(
        organization: $organization,
        event: expressionEvent($organization, 'cached_metric', ['v' => '2.4']),
    );

    expect($result->success())->toBeTrue()
        ->and($result->event->properties['result'])->toBe('2.0');

    // Delete the metric — the cached pair must keep serving, like Rails.
    $organization->billableMetrics()->delete();

    $second = CalculateExpressionService::call(
        organization: $organization,
        event: expressionEvent($organization, 'cached_metric', ['v' => '2.6']),
    );

    expect($second->success())->toBeTrue()
        ->and($second->event->properties['result'])->toBe('3.0');

    ExpressionCacheService::expireCache((string) $organization->id, 'cached_metric');

    $third = CalculateExpressionService::call(
        organization: $organization,
        event: expressionEvent($organization, 'cached_metric', ['v' => '2.6']),
    );

    expect($third->success())->toBeTrue()
        ->and($third->event->properties)->not->toHaveKey('result');
});

it('fails with the service failure code when evaluation raises', function (): void {
    $organization = Organization::factory()->create();
    App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'code' => 'failing_metric',
        'field_name' => 'result',
        'expression' => 'event.properties.missing + 1',
    ]);

    $event = expressionEvent($organization, 'failing_metric', []);
    $result = CalculateExpressionService::call(organization: $organization, event: $event);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ServiceFailure::class)
        ->and($result->getError()->getMessage())
        ->toBe('expression_evaluation_failed: Variable: missing not found');
});

it('parses stored expressions through the parser port', function (): void {
    // The gem contract the CalculateExpressionService relies on: a saved
    // expression always parses; the evaluator carries the runtime errors.
    $expression = Parser::parse('event.properties.a * 2 + greatest(event.properties.b, 0)');

    $value = (new Evaluator)->evaluate($expression, new ExpressionEvent(
        code: 'x',
        timestamp: 1,
        properties: ['a' => '3', 'b' => '-5'],
    ));

    expect($value->toRailsString())->toBe('6.0');
});
