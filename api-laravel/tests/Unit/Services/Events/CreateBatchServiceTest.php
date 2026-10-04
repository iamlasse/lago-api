<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Jobs\Events\PostProcessJob;
use Illuminate\Support\Facades\Queue;
use App\Services\Events\CreateBatchService;

uses()->group('ledger:svc:Events.CreateBatchService');

/**
 * Port of Rails' spec/services/events/create_batch_service_spec.rb.
 */
function batchOrganization(): Organization
{
    return Organization::factory()->create();
}

function callCreateBatch(Organization $organization, array $eventsParams, array $metadata = []): App\Services\BaseResult
{
    return CreateBatchService::call(
        organization: $organization,
        eventsParams: $eventsParams,
        timestamp: 1780586630.5,
        metadata: $metadata,
    );
}

it('creates a batch of events', function (): void {
    $organization = batchOrganization();

    $result = callCreateBatch($organization, ['events' => [[
        'code' => 'sum_agg',
        'transaction_id' => 'batch_txn_1',
        'external_subscription_id' => 'sub_1',
        'properties' => ['foo' => 'bar'],
        'timestamp' => '1780586634.123',
    ]]]);

    expect($result->success())->toBeTrue()
        ->and(Event::query()->count())->toBe(1)
        ->and($result->events[0]->id)->not->toBeNull()
        ->and($result->events[0]->created_at)->not->toBeNull()
        ->and($result->events[0]->timestamp->format('Y-m-d H:i:s.u'))->toBe('2026-06-04 15:23:54.123000');

    Queue::assertPushed(PostProcessJob::class, 1);
});

it('answers no_events for a blank batch', function (): void {
    $result = callCreateBatch(batchOrganization(), ['events' => []]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['events' => ['no_events']]);
});

it('answers no_events when the events param is absent', function (): void {
    $result = callCreateBatch(batchOrganization(), []);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['events' => ['no_events']]);
});

it('answers too_many_events over the max batch length', function (): void {
    $organization = batchOrganization();

    $events = [];
    for ($i = 0; $i < 101; $i++) {
        $events[] = ['transaction_id' => 'txn_'.$i, 'code' => 'sum_agg'];
    }

    $result = callCreateBatch($organization, ['events' => $events]);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['events' => ['too_many_events']]);
});

it('honors LAGO_EVENTS_BATCH_MAX_LENGTH', function (): void {
    putenv('LAGO_EVENTS_BATCH_MAX_LENGTH=2');

    try {
        $result = callCreateBatch(batchOrganization(), ['events' => [
            ['transaction_id' => 'a', 'code' => 'sum_agg'],
            ['transaction_id' => 'b', 'code' => 'sum_agg'],
            ['transaction_id' => 'c', 'code' => 'sum_agg'],
        ]]);

        expect($result->getError()->messages)->toBe(['events' => ['too_many_events']]);
    } finally {
        putenv('LAGO_EVENTS_BATCH_MAX_LENGTH');
    }
});

it('reports per-index validation errors', function (): void {
    $organization = batchOrganization();

    $result = callCreateBatch($organization, ['events' => [
        ['code' => 'sum_agg', 'transaction_id' => 'ok_txn', 'timestamp' => '1780586634.1'],
        ['code' => 'sum_agg', 'transaction_id' => 'bad_ts', 'timestamp' => 'not a timestamp'],
    ]]);

    expect($result->success())->toBeFalse()
        ->and(Event::query()->count())->toBe(0)
        // A PHP array with int keys would render a list; Rails emits {"1": ...}.
        ->and((array) $result->getError()->messages)->toHaveKey('1')
        ->and($result->getError()->messages->{'1'})->toBe(['timestamp' => ['invalid_format']]);
});

it('reports blank transaction_id per index', function (): void {
    $organization = batchOrganization();

    $result = callCreateBatch($organization, ['events' => [
        ['code' => 'sum_agg', 'timestamp' => '1780586634.1'],
    ]]);

    expect($result->success())->toBeFalse()
        ->and(Event::query()->count())->toBe(0)
        // Lago's en.yml maps the ActiveRecord "blank" message to the
        // error code "value_is_mandatory" (contract finding 19).
        ->and($result->getError()->messages->{'0'})->toBe(['transaction_id' => ['value_is_mandatory']]);
});

it('reports duplicate transaction_ids within the payload', function (): void {
    $organization = batchOrganization();

    $event = ['code' => 'sum_agg', 'external_subscription_id' => 'sub_dup', 'timestamp' => '1780586634.1'];

    $result = callCreateBatch($organization, ['events' => [
        array_merge($event, ['transaction_id' => 'dup_txn']),
        array_merge($event, ['transaction_id' => 'dup_txn']),
    ]]);

    expect($result->success())->toBeFalse()
        ->and(Event::query()->count())->toBe(0)
        ->and($result->getError()->messages->{'1'})->toBe(['transaction_id' => ['value_already_exist']]);
});

it('reports duplicates against stored events', function (): void {
    $organization = batchOrganization();

    Event::factory()->create([
        'organization_id' => $organization->id,
        'transaction_id' => 'stored_txn',
        'external_subscription_id' => 'sub_stored',
    ]);

    $result = callCreateBatch($organization, ['events' => [[
        'code' => 'sum_agg',
        'transaction_id' => 'stored_txn',
        'external_subscription_id' => 'sub_stored',
        'timestamp' => '1780586634.1',
    ]]]);

    expect($result->success())->toBeFalse()
        ->and(Event::query()->count())->toBe(1)
        ->and($result->getError()->messages->{'0'})->toBe(['transaction_id' => ['value_already_exist']])
        ->and(Queue::pushedJobs(PostProcessJob::class))->toBe([]);
});

it('evaluates batch expressions and stores the result', function (): void {
    $organization = batchOrganization();

    App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'code' => 'sum_agg',
        'field_name' => 'value',
        'expression' => 'event.properties.a + event.properties.b',
    ]);

    $result = callCreateBatch($organization, ['events' => [[
        'code' => 'sum_agg',
        'transaction_id' => 'expr_txn',
        'external_subscription_id' => 'sub_expr',
        'timestamp' => '1780586634.1',
        'properties' => ['a' => '1', 'b' => '2'],
    ]]]);

    expect($result->success())->toBeTrue()
        ->and($result->events[0]->properties['value'])->toBe('3.0')
        ->and(Event::query()->first()->properties['value'])->toBe('3.0');
});

it('reports a batch expression failure per index', function (): void {
    $organization = batchOrganization();

    App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'code' => 'sum_agg',
        'field_name' => 'value',
        'expression' => 'event.properties.a + event.properties.b',
    ]);

    $result = callCreateBatch($organization, ['events' => [[
        'code' => 'sum_agg',
        'transaction_id' => 'expr_partial',
        'external_subscription_id' => 'sub_expr',
        'timestamp' => '1780586634.1',
        'properties' => ['a' => '1'],
    ]]]);

    expect($result->success())->toBeFalse()
        ->and(Event::query()->count())->toBe(0)
        ->and($result->getError()->messages->{'0'})
        ->toBe('expression_evaluation_failed: Variable: b not found');
});

it('uses the service timestamp when an event has none', function (): void {
    $organization = batchOrganization();

    $result = callCreateBatch($organization, ['events' => [[
        'code' => 'sum_agg',
        'transaction_id' => 'no_ts_txn',
        'external_subscription_id' => 'sub_1',
    ]]]);

    expect($result->success())->toBeTrue()
        ->and($result->events[0]->timestamp->format('Y-m-d H:i:s.u'))->toBe('2026-06-04 15:23:50.500000');
});
