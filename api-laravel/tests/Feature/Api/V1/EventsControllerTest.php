<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/events',
    'ledger:rest:POST:/api/v1/events/batch',
    'ledger:rest:GET:/api/v1/events',
    'ledger:rest:GET:/api/v1/events/:id',
    'ledger:rest:GET:/api/v1/events_enriched',
);

use App\Models\Event;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use App\Jobs\Events\PostProcessJob;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/requests/api/v1/events_controller_spec.rb.
 *
 * Scenarios not ported (dependencies do not exist yet):
 * - the estimate_fees / estimate_instant_fees / batch_estimate_instant_fees
 *   scenarios (Fees::EstimateInstant::PayInAdvanceService family — the
 *   pay-in-advance metering slice);
 * - every `clickhouse: true` tagged scenario (Clickhouse::EventsRaw /
 *   EventsEnriched — the ClickHouse store is not ported; on this stack
 *   organizations use the postgres events store, Rails' pg_event? branch).
 *
 * The expression scenarios run on the App\Expression parser port (the
 * lago-expression gem).
 */
function eventOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function eventSubscription(Organization $organization, array $attributes = []): Subscription
{
    return Subscription::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'started_at' => now()->subMonth(),
    ], $attributes));
}

// -- POST /api/v1/events ---------------------------------------------------------

it('creates an event', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

    expect(Event::query()->count())->toBe(0);

    $response = $this->postJson('/api/v1/events', ['event' => [
        'code' => $metric->code,
        'transaction_id' => 'txn_1',
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => now()->getTimestamp(),
        'precise_total_amount_cents' => '123.45',
        'properties' => ['foo' => 'bar'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('event.external_subscription_id', $subscription->external_id)
        ->assertJsonPath('event.transaction_id', 'txn_1');

    expect(Event::query()->count())->toBe(1);

    // Rails enqueues Events::PostProcessJob after saving.
    Queue::assertPushed(PostProcessJob::class);

    $response->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->where('event.properties', ['foo' => 'bar'])
            ->where('event.precise_total_amount_cents', '0.12345e3')
            ->has('event.lago_id')
            ->has('event.created_at')
            ->etc();
    });
});

it('stores a v2 external_contract_id under external_subscription_id', function (): void {
    [$organization, $apiKey] = eventOrganization(['feature_flags' => ['product_catalog']]);
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/events', ['event' => [
        'code' => $metric->code,
        'transaction_id' => 'txn_contract',
        'external_contract_id' => 'contract_external_id',
        'timestamp' => now()->getTimestamp(),
        'properties' => ['foo' => 'bar'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('event.external_subscription_id', 'contract_external_id');

    expect(Event::query()->oldest()->first()->external_subscription_id)
        ->toBe('contract_external_id');
});

it('keeps the explicit external_subscription_id when both ids are sent', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/events', ['event' => [
        'code' => $metric->code,
        'transaction_id' => 'txn_both',
        'external_subscription_id' => $subscription->external_id,
        'external_contract_id' => 'ignored-contract-id',
        'timestamp' => now()->getTimestamp(),
        'properties' => ['foo' => 'bar'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('event.external_subscription_id', $subscription->external_id);
});

it('rejects a duplicated transaction_id', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'external_subscription_id' => $subscription->external_id,
    ]);

    $this->postJson('/api/v1/events', ['event' => [
        'code' => $metric->code,
        'transaction_id' => $event->transaction_id,
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => now()->getTimestamp(),
        'precise_total_amount_cents' => '123.45',
        'properties' => ['foo' => 'bar'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable();

    expect(Event::query()->count())->toBe(1);
});

it('rejects a wrong timestamp format', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/events', ['event' => [
        'code' => $metric->code,
        'transaction_id' => 'txn_bad_ts',
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => now()->toDateTimeString(),
        'precise_total_amount_cents' => '123.45',
        'properties' => ['foo' => 'bar'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['timestamp' => ['invalid_format']],
        ]);

    expect(Event::query()->count())->toBe(0);
});

it('evaluates the expression of the billable metric and stores the result', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'field_name' => 'value',
        'expression' => 'event.properties.a + event.properties.b',
    ]);

    $this->postJson('/api/v1/events', ['event' => [
        'code' => $metric->code,
        'transaction_id' => 'txn_expr',
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => now()->getTimestamp(),
        'properties' => ['a' => '1', 'b' => '2'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('event.properties.value', '3.0');
});

it('fails with 422 when the properties are incomplete for the expression', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'field_name' => 'value',
        'expression' => 'event.properties.a + event.properties.b',
    ]);

    $this->postJson('/api/v1/events', ['event' => [
        'code' => $metric->code,
        'transaction_id' => 'txn_expr_partial',
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => now()->getTimestamp(),
        'properties' => ['a' => '1'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            // Rails: error_details is the bare ServiceFailure message string.
            $json->where('error_details', 'expression_evaluation_failed: Variable: b not found')->etc();
        });

    expect(Event::query()->count())->toBe(0);
});

// -- POST /api/v1/events/batch ---------------------------------------------------

it('creates a batch of events', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/events/batch', ['events' => [[
        'code' => $metric->code,
        'transaction_id' => 'batch_1',
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => now()->getTimestamp(),
        'precise_total_amount_cents' => '123.45',
        'properties' => ['foo' => 'bar'],
    ]]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('events.0.external_subscription_id', $subscription->external_id);

    expect(Event::query()->count())->toBe(1);
    Queue::assertPushed(PostProcessJob::class);
});

it('stores a batch external_contract_id under external_subscription_id', function (): void {
    [$organization, $apiKey] = eventOrganization(['feature_flags' => ['product_catalog']]);
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/events/batch', ['events' => [[
        'code' => $metric->code,
        'transaction_id' => 'batch_contract',
        'external_contract_id' => 'contract_external_id',
        'timestamp' => now()->getTimestamp(),
        'properties' => ['foo' => 'bar'],
    ]]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('events.0.external_subscription_id', 'contract_external_id');
});

it('reports which batch event carried an invalid timestamp', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/events/batch', ['events' => [
        [
            'code' => $metric->code,
            'transaction_id' => 'batch_ok',
            'external_subscription_id' => $subscription->external_id,
            'timestamp' => now()->getTimestamp(),
            'precise_total_amount_cents' => '123.45',
            'properties' => ['foo' => 'bar'],
        ],
        [
            'code' => $metric->code,
            'transaction_id' => 'batch_bad',
            'external_subscription_id' => $subscription->external_id,
            'timestamp' => now()->toDateTimeString(),
            'precise_total_amount_cents' => '123.45',
            'properties' => ['foo' => 'bar'],
        ],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['1' => ['timestamp' => ['invalid_format']]],
        ]);

    expect(Event::query()->count())->toBe(0);
});

it('evaluates batch expressions and stores the result', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'field_name' => 'value',
        'expression' => 'event.properties.a + event.properties.b',
    ]);

    $this->postJson('/api/v1/events/batch', ['events' => [[
        'code' => $metric->code,
        'transaction_id' => 'batch_expr',
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => now()->getTimestamp(),
        'properties' => ['a' => '1', 'b' => '2'],
    ]]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('events.0.properties.value', '3.0');
});

it('reports a batch expression failure per index', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create([
        'field_name' => 'value',
        'expression' => 'event.properties.a + event.properties.b',
    ]);

    $this->postJson('/api/v1/events/batch', ['events' => [[
        'code' => $metric->code,
        'transaction_id' => 'batch_expr_partial',
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => now()->getTimestamp(),
        'properties' => ['a' => '1'],
    ]]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('error_details.0', 'expression_evaluation_failed: Variable: b not found')->etc();
        });

    expect(Event::query()->count())->toBe(0);
});

// -- GET /api/v1/events ----------------------------------------------------------

it('lists events', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'timestamp' => now()->subDays(5),
    ]);

    $this->getJson('/api/v1/events', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($event): void {
            $json->count('events', 1)
                ->where('events.0.lago_id', $event->id)
                ->etc();
        });
});

it('paginates events with the meta envelope', function (): void {
    [$organization, $apiKey] = eventOrganization();
    Event::factory()->create(['organization_id' => $organization->id]);
    Event::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/events?page=1&per_page=1', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('events', 1)
                ->where('meta.current_page', 1)
                ->where('meta.next_page', 2)
                ->where('meta.prev_page', null)
                ->where('meta.total_pages', 2)
                ->where('meta.total_count', 2)
                ->etc();
        });
});

it('filters events by code', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    Event::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/events?code='.$event->code, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($event): void {
            $json->count('events', 1)->where('events.0.lago_id', $event->id)->etc();
        });
});

it('filters events by external subscription id', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    Event::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/events?external_subscription_id='.$event->external_subscription_id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($event): void {
        $json->count('events', 1)->where('events.0.lago_id', $event->id)->etc();
    });
});

it('filters events by timestamp range', function (): void {
    [$organization, $apiKey] = eventOrganization();
    Event::factory()->create(['organization_id' => $organization->id, 'timestamp' => now()->subDays(5)]);
    Event::factory()->create(['organization_id' => $organization->id, 'timestamp' => now()->subDays(3)]);
    $matching = Event::factory()->create(['organization_id' => $organization->id, 'timestamp' => now()->subDay()]);

    $this->getJson('/api/v1/events?timestamp_from='.now()->subDays(2)->toDateString().'&timestamp_to='.now()->addDay()->toDateString(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($matching): void {
        $json->count('events', 1)->where('events.0.lago_id', $matching->id)->etc();
    });
});

it('filters events from the subscription start with timestamp_from_started_at', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $startedAt = now()->subDay()->startOfSecond();
    $subscription = eventSubscription($organization, ['started_at' => $startedAt]);

    $matching = Event::factory()->create([
        'organization_id' => $organization->id,
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => $startedAt->copy()->addSecond(),
    ]);
    Event::factory()->create([
        'organization_id' => $organization->id,
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => $startedAt->copy()->subSecond(),
    ]);

    $this->getJson('/api/v1/events?timestamp_from_started_at=true&external_subscription_id='.$subscription->external_id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($matching): void {
        $json->count('events', 1)->where('events.0.lago_id', $matching->id)->etc();
    });
});

it('ignores timestamp_from_started_at set to the string false', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $startedAt = now()->subDay()->startOfSecond();
    $subscription = eventSubscription($organization, ['started_at' => $startedAt]);

    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'timestamp' => now()->subDays(10),
    ]);
    $other = Event::factory()->create([
        'organization_id' => $organization->id,
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => $startedAt->copy()->subSecond(),
    ]);
    $matching = Event::factory()->create([
        'organization_id' => $organization->id,
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => $startedAt->copy()->addSecond(),
    ]);

    $this->getJson('/api/v1/events?timestamp_from='.now()->subDays(20)->toDateString().'&timestamp_from_started_at=false', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->count('events', 3)->etc();
    });
});

// -- GET /api/v1/events_enriched -------------------------------------------------

it('answers endpoint_not_available for events_enriched without clickhouse', function (): void {
    [$organization, $apiKey] = eventOrganization();

    $this->getJson('/api/v1/events_enriched', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden();
});

// -- GET /api/v1/events/:id ------------------------------------------------------

it('shows an event', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/events/'.$event->transaction_id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('event.code', $event->code)
        ->assertJsonPath('event.transaction_id', $event->transaction_id)
        ->assertJsonPath('event.lago_subscription_id', $event->subscription_id)
        ->assertJsonPath('event.lago_customer_id', $event->customer_id);
});

it('shows an event whose transaction_id contains special characters', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $transactionId = '1Az()[]?#._/|-/../';
    $event = Event::factory()->create([
        'organization_id' => $organization->id,
        'transaction_id' => $transactionId,
    ]);

    $this->getJson('/api/v1/events/'.rawurlencode($transactionId), ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('event.transaction_id', $transactionId);
});

it('answers not found for a non-existing transaction_id', function (): void {
    [$organization, $apiKey] = eventOrganization();

    $this->getJson('/api/v1/events/nope', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('answers not found for a deleted event', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $event = Event::factory()->create(['organization_id' => $organization->id, 'deleted_at' => now()]);

    $this->getJson('/api/v1/events/'.$event->transaction_id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- v2 mirror -------------------------------------------------------------------

it('mirrors the events endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = eventOrganization();
    $subscription = eventSubscription($organization);
    $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v2/events', ['event' => [
        'code' => $metric->code,
        'transaction_id' => 'v2_txn',
        'external_subscription_id' => $subscription->external_id,
        'timestamp' => now()->getTimestamp(),
        'properties' => ['foo' => 'bar'],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('event.transaction_id', 'v2_txn');

    $this->getJson('/api/v2/events', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('meta.total_count', 1);

    $this->getJson('/api/v2/events_enriched', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});

// -- api permissions -------------------------------------------------------------

it('requires an api permission to write events', function (): void {
    withEventPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = eventOrganization();

        DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['event' => ['read']]), $apiKey->id],
        );

        $this->postJson('/api/v1/events', ['event' => [
            'code' => 'any_code',
            'transaction_id' => 'txn_perm',
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertForbidden()
            ->assertExactJson([
                'status' => 403,
                'error' => 'Forbidden',
                'code' => 'write_action_not_allowed_for_event',
            ]);
    });
});

it('allows the write when the api permission grants it', function (): void {
    withEventPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = eventOrganization();
        $subscription = eventSubscription($organization);
        $metric = App\Models\BillableMetric::factory()->forOrganization($organization)->create();

        DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['event' => ['write']]), $apiKey->id],
        );

        $this->postJson('/api/v1/events', ['event' => [
            'code' => $metric->code,
            'transaction_id' => 'txn_perm_ok',
            'external_subscription_id' => $subscription->external_id,
            'timestamp' => now()->getTimestamp(),
            'properties' => ['foo' => 'bar'],
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertOk()
            ->assertJsonPath('event.transaction_id', 'txn_perm_ok');
    });
});

/**
 * Rails' :premium spec tag — License.premium? is true while a license key
 * is configured.
 */
function withEventPremiumLicense(callable $scenario): void
{
    config(['lago.license' => 'premium-license-token']);

    try {
        $scenario();
    } finally {
        config(['lago.license' => null]);

    }
}
