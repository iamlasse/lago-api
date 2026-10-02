<?php

declare(strict_types=1);

use App\Models\Invoice;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\BillableMetric;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\NotFoundFailure;
use App\Services\BillableMetrics\DestroyService;

beforeEach(function (): void {
    CurrentContext::reset();
});

function destroyMetricFixture(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $metric = BillableMetric::factory()->for($organization)->sum()->create();

    $planId = (string) Str::uuid();
    DB::table('plans')->insert([
        'id' => $planId,
        'organization_id' => $organization->id,
        'name' => 'Standard',
        'code' => 'standard',
        'amount_currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $chargeId = (string) Str::uuid();
    DB::table('charges')->insert([
        'id' => $chargeId,
        'organization_id' => $organization->id,
        'plan_id' => $planId,
        'billable_metric_id' => $metric->id,
        'code' => 'standard',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$organization, $metric, $planId, $chargeId];
}

function draftInvoiceForPlan(object $organization, string $planId): string
{
    $invoiceId = (string) Str::uuid();
    DB::table('invoices')->insert([
        'id' => $invoiceId,
        'organization_id' => $organization->id,
        'billing_entity_id' => $organization->defaultBillingEntity->id,
        'plan_id' => $planId,
        'status' => 0, // draft
        'issuing_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $invoiceId;
}

it('soft deletes the billable metric', function (): void {
    [, $metric] = destroyMetricFixture();

    $result = DestroyService::call(metric: $metric);

    expect($result->success())->toBeTrue()
        ->and(BillableMetric::count())->toBe(0)
        ->and($metric->fresh()->trashed())->toBeTrue();
})->group('ledger:svc:BillableMetrics.DestroyService');

it('soft deletes the related charges', function (): void {
    [, $metric, , $chargeId] = destroyMetricFixture();

    DestroyService::call(metric: $metric);

    expect(DB::table('charges')->where('id', $chargeId)->value('deleted_at'))->not->toBeNull();
});

it('marks the draft invoices of the attached plans as ready to be refreshed', function (): void {
    [$organization, $metric, $planId] = destroyMetricFixture();
    $invoiceId = draftInvoiceForPlan($organization, $planId);

    DestroyService::call(metric: $metric);

    expect(Invoice::query()->find($invoiceId)->ready_to_be_refreshed)->toBeTrue();
});

it('fails when the billable metric is not found', function (): void {
    $result = DestroyService::call(metric: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('billable_metric_not_found');
});

// TODO(port): the alerts soft delete (UsageMonitoring::Alert), the filters
// destroy job (BillableMetricFilters::DestroyAllJob), the product filter
// values guard, the webhook (SendWebhookJob "billable_metric.deleted") and the
// activity log scenarios from the Rails spec are covered by TODO(port) hook
// points in the service.
