<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Jobs\Events\PostProcessJob;
use Illuminate\Support\Facades\Queue;
use App\Services\Events\CreateService;
use App\Services\Failures\ValidationFailure;

uses()->group('ledger:svc:Events.CreateService');

/**
 * Port of Rails' spec/services/events/create_service_spec.rb.
 */
function createServiceOrganization(): Organization
{
    return Organization::factory()->create();
}

function createServiceArgs(array $overrides = []): array
{
    return array_merge([
        'external_subscription_id' => 'sub_'.bin2hex(random_bytes(6)),
        'code' => 'sum_agg',
        'transaction_id' => 'txn_'.bin2hex(random_bytes(6)),
        'precise_total_amount_cents' => null,
        'properties' => ['foo' => 'bar'],
        'timestamp' => '1780586634.123',
    ], $overrides);
}

function callCreateService(Organization $organization, array $params, array $metadata = []): App\Services\BaseResult
{
    return CreateService::call(
        organization: $organization,
        params: $params,
        timestamp: 1780586630.5,
        metadata: $metadata,
    );
}

it('creates an event', function (): void {
    $organization = createServiceOrganization();
    $args = createServiceArgs();

    $result = null;
    $result = callCreateService($organization, $args);

    expect($result->success())->toBeTrue()
        ->and(Event::query()->count())->toBe(1)
        ->and($result->event->external_subscription_id)->toBe($args['external_subscription_id'])
        ->and($result->event->transaction_id)->toBe($args['transaction_id'])
        ->and($result->event->code)->toBe('sum_agg')
        ->and($result->event->timestamp->format('Y-m-d H:i:s.u'))->toBe('2026-06-04 15:23:54.123000')
        ->and($result->event->properties)->toBe(['foo' => 'bar'])
        ->and($result->event->precise_total_amount_cents)->toBeNull();
});

it('enqueues a post processing job', function (): void {
    $organization = createServiceOrganization();

    callCreateService($organization, createServiceArgs());

    Queue::assertPushed(PostProcessJob::class);
});

it('does not keep the event when the post processing job cannot be enqueued', function (): void {
    $organization = createServiceOrganization();

    // Rails stubs the queue adapter's enqueue to raise — swap a throwing
    // queue in so the dispatch itself fails.
    $queue = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $queue->shouldReceive('connection')->andReturnSelf();
    $queue->shouldReceive('push')->andThrow(new RuntimeException('no connection'));
    Queue::swap($queue);

    try {
        callCreateService($organization, createServiceArgs());

        $this->fail('Expected the enqueue failure to surface');
    } catch (RuntimeException) {
        // `index_unique_transaction_id` has no `deleted_at` predicate, so keeping
        // the event would answer the caller's retry with value_already_exist forever.
        expect(Event::query()->count())->toBe(0);
    }
});

it('returns value_already_exist when the event already exists', function (): void {
    $organization = createServiceOrganization();
    $externalSubscriptionId = 'sub_existing';
    $transactionId = 'txn_existing';

    Event::factory()->create([
        'organization_id' => $organization->id,
        'transaction_id' => $transactionId,
        'external_subscription_id' => $externalSubscriptionId,
    ]);

    $result = callCreateService($organization, createServiceArgs([
        'transaction_id' => $transactionId,
        'external_subscription_id' => $externalSubscriptionId,
    ]));

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['transaction_id' => ['value_already_exist']])
        ->and(Event::query()->count())->toBe(1);
});

it('creates an event with the current datetime when the timestamp is not present', function (): void {
    Illuminate\Support\Facades\Date::setTestNow('2026-06-04 15:23:54.5');
    $organization = createServiceOrganization();

    $result = callCreateService($organization, createServiceArgs(['timestamp' => null]));

    expect($result->success())->toBeTrue()
        ->and($result->event->timestamp->format('Y-m-d H:i:s.u'))->toBe('2026-06-04 15:23:50.500000');
});

it('creates an event from a string timestamp', function (): void {
    $organization = createServiceOrganization();

    $result = callCreateService($organization, createServiceArgs(['timestamp' => '1780586634.1']));

    expect($result->success())->toBeTrue()
        ->and($result->event->timestamp->format('Y-m-d H:i:s.u'))->toBe('2026-06-04 15:23:54.100000');
});

it('keeps the millisecond precision of a decimal timestamp', function (): void {
    $organization = createServiceOrganization();

    $result = callCreateService($organization, createServiceArgs(['timestamp' => '1693844712.344']));

    expect($result->success())->toBeTrue()
        // Rails: event.timestamp.iso8601(3) — ms precision preserved
        ->and($result->event->timestamp->utc()->format('Y-m-d\TH:i:s.v\Z'))->toBe('2023-09-04T16:25:12.344Z');
});

it('keeps the received precision of repeating-float timestamps', function (): void {
    $organization = createServiceOrganization();

    $results = array_map(
        fn (string $receivedTimestamp): App\Services\BaseResult => callCreateService(
            $organization,
            createServiceArgs(['timestamp' => $receivedTimestamp]),
        ),
        ['1780586634.1', '1780586634.2', '1780586634.3'],
    );

    expect(array_map(
        fn (App\Services\BaseResult $result): string => $result->event->timestamp->format('Y-m-d H:i:s.u'),
        $results,
    ))->toBe([
        '2026-06-04 15:23:54.100000',
        '2026-06-04 15:23:54.200000',
        '2026-06-04 15:23:54.300000',
    ]);
});

it('returns invalid_format for a wrong timestamp', function (): void {
    $organization = createServiceOrganization();

    $result = callCreateService($organization, createServiceArgs([
        'timestamp' => now()->toDateTimeString(),
    ]));

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe(['timestamp' => ['invalid_format']]);
});

it('evaluates the expression and updates the field with the result', function (): void {
    $organization = createServiceOrganization();
    $code = 'sum_agg';

    App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'code' => $code,
        'field_name' => 'result',
        'expression' => 'event.properties.left + event.properties.right',
    ]);

    $result = callCreateService($organization, createServiceArgs([
        'code' => $code,
        'properties' => ['left' => '1', 'right' => '2'],
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->event->properties['result'])->toBe('3.0');
});

it('fails when the expression cannot be evaluated', function (): void {
    $organization = createServiceOrganization();
    $code = 'sum_agg';

    App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'code' => $code,
        'field_name' => 'result',
        'expression' => 'event.properties.left + event.properties.right',
    ]);

    $result = callCreateService($organization, createServiceArgs([
        'code' => $code,
        'properties' => [],
    ]));

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages)->toBe('expression_evaluation_failed: Variable: left not found')
        ->and(Event::query()->count())->toBe(0);
});

it('creates an event with the precise_total_amount_cents', function (): void {
    $organization = createServiceOrganization();

    $result = callCreateService($organization, createServiceArgs(['precise_total_amount_cents' => '123.45']));

    expect($result->success())->toBeTrue()
        ->and($result->event->precise_total_amount_cents)->toBe('123.45');
});

it('casts a non-numeric precise_total_amount_cents to zero', function (): void {
    $organization = createServiceOrganization();

    $result = callCreateService($organization, createServiceArgs(['precise_total_amount_cents' => 'asdfa']));

    expect($result->success())->toBeTrue()
        ->and($result->event->precise_total_amount_cents)->toBe('0');
});
