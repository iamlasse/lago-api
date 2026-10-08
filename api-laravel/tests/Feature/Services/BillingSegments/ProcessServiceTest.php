<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\RateCard;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Models\RateCardRate;
use App\Models\RateOverride;
use App\Models\BillingSegment;
use App\Models\ContractRateCard;
use Illuminate\Support\Facades\DB;
use App\Services\BillingSegments\ProcessService;
use App\Services\Failures\LockAcquisitionFailure;

/**
 * Port of spec/services/billing_segments/process_service_spec.rb — the
 * fixed-product subset (the metered path rides the usage/records slice, see
 * the TODO(port) in ProcessService).
 */
function processFixture(): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'USD',
    ]);
    $contract = Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'consolidate_invoice' => true,
        'started_at' => CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
    ]);
    $product = Product::factory()->fixed()->create(['organization_id' => $organization->id]);
    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'currency' => 'USD',
    ]);
    $contractRateCard = ContractRateCard::factory()->create([
        'organization_id' => $organization->id,
        'contract_id' => $contract->id,
        'rate_card_id' => $rateCard->id,
        'units' => 5,
        'effective_date' => '2026-07-01',
    ]);
    $rateOverride = RateOverride::factory()->create([
        'organization_id' => $organization->id,
        'rate_properties' => ['amount' => '15.00'],
    ]);

    return compact('organization', 'customer', 'contract', 'product', 'rateCard', 'contractRateCard', 'rateOverride');
}

function processSegment(array $fixture, ?RateOverride $rateOverride): BillingSegment
{
    return BillingSegment::factory()->create([
        'organization_id' => $fixture['organization']->id,
        'contract_id' => $fixture['contract']->id,
        'customer_id' => $fixture['customer']->id,
        'contract_rate_card_id' => $fixture['contractRateCard']->id,
        'rate_card_rate_id' => RateCardRate::factory()->create([
            'organization_id' => $fixture['organization']->id,
            'rate_card_id' => $fixture['rateCard']->id,
            'rate_model' => 'standard',
            'rate_properties' => ['amount' => '30.00'],
        ])->id,
        'rate_override_id' => $rateOverride?->id,
        'currency' => 'USD',
        'rate_properties' => ['amount' => $rateOverride !== null ? '15.00' : '30.00'],
        'proration_ratio' => '1.0',
        'billing_at' => CarbonImmutable::parse('2026-08-31 23:59:59', 'UTC'),
        'cycle_started_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'),
        'started_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-08-31 23:59:59', 'UTC'),
    ]);
}

it('creates a finalized invoice priced from the snapshotted rate override', function (): void {
    extract(processFixture());
    processSegment(compact('organization', 'customer', 'contract', 'product', 'rateCard', 'contractRateCard'), $rateOverride);

    $result = ProcessService::callBang(customer: $customer);

    $invoice = $result->invoices[0]->refresh();

    expect($invoice->status->label())->toBe('finalized')
        ->and((int) $invoice->total_amount_cents)->toBe(75_00);

    $fee = $invoice->fees->sole();

    // Standard model: units 5 x amount 15.00 (per-unit) = 7500 cents.
    expect($fee->fee_type)->toBe(App\Enums\FeeType::Product)
        ->and((int) $fee->amount_cents)->toBe(75_00)
        ->and((int) $fee->unit_amount_cents)->toBe(15_00)
        ->and((float) $fee->units)->toBe(5.0)
        ->and($fee->rate_override_id)->toBe($rateOverride->id)
        ->and($fee->contract_rate_card_id)->toBe($contractRateCard->id)
        ->and($fee->invoiceable_id)->toBe($product->id)
        ->and($fee->properties['billing_segment_id'])->toBe($invoice->fees->sole()->properties['billing_segment_id']);

    // The served segment is done and linked to the invoice.
    $segment = BillingSegment::query()->sole();

    expect($segment->status->value)->toBe('done')
        ->and($segment->invoice_id)->toBe($invoice->id);
});

it('prices the fee from the stored rate when no override exists', function (): void {
    extract(processFixture());
    processSegment(compact('organization', 'customer', 'contract', 'product', 'rateCard', 'contractRateCard'), null);

    $result = ProcessService::callBang(customer: $customer);

    expect((int) $result->invoices[0]->refresh()->total_amount_cents)->toBe(150_00);
});

it('does not create duplicate invoices or fees on repeated invocation', function (): void {
    extract(processFixture());
    processSegment(compact('organization', 'customer', 'contract', 'product', 'rateCard', 'contractRateCard'), $rateOverride);

    ProcessService::callBang(customer: $customer);

    $invoices = Invoice::query()->count();
    $fees = App\Models\Fee::query()->count();

    ProcessService::callBang(customer: $customer);

    expect(Invoice::query()->count())->toBe($invoices)
        ->and(App\Models\Fee::query()->count())->toBe($fees);
});

it('does not process another customer pending segments', function (): void {
    extract(processFixture());
    processSegment(compact('organization', 'customer', 'contract', 'product', 'rateCard', 'contractRateCard'), $rateOverride);

    $otherCustomer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'USD',
    ]);

    $result = ProcessService::callBang(customer: $otherCustomer);

    expect($result->invoices)->toBe([])
        ->and(BillingSegment::query()->sole()->status->value)->toBe('pending');
});

it('raises the retryable lock failure when the advisory lock cannot be acquired', function (): void {
    extract(processFixture());

    // Rails stubs with_advisory_lock to return false. Here a REAL conflict:
    // a second connection holds the same transaction-level advisory lock, so
    // the service's 0-timeout try must fail.
    config(['database.connections.seglock_test' => config('database.connections.pgsql')]);
    $lockConn = DB::connection('seglock_test');
    $lockConn->beginTransaction();
    $lockConn->statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["customer-{$customer->id}-billing_schedule"]);

    try {
        ProcessService::callBang(customer: $customer);
        $this->fail('expected LockAcquisitionFailure');
    } catch (LockAcquisitionFailure $e) {
        expect($e->getMessage())->toContain('Failed to acquire billing segment lock for customer');
    } finally {
        $lockConn->rollBack();
    }
});

it('keys the invoice by customer local billing date consolidation currency entity po and payment method', function (): void {
    extract(processFixture());
    $segment = processSegment(compact('organization', 'customer', 'contract', 'product', 'rateCard', 'contractRateCard'), $rateOverride);

    $service = new ProcessService($customer);
    $method = new ReflectionMethod(ProcessService::class, 'invoiceKey');
    $method->setAccessible(true);

    // Customer in UTC: billing date = 2026-08-31; consolidated → 'shared'.
    expect($method->invoke($service, $segment))->toBe([
        '2026-08-31',
        'shared',
        'USD',
        // Contract has no entity of its own → falls back to the customer's default.
        (string) $customer->fresh()->billing_entity_id,
        '|provider',
        '',
    ]);

    // Non-consolidated → the segment id itself is the bucket.
    $contract->update(['consolidate_invoice' => false]);
    $segment->refresh();

    expect($method->invoke($service, $segment)[1])->toBe($segment->id);

    // Customer-local billing date: 23:59 UTC lands on Sep 1 east of UTC.
    $customer->update(['timezone' => 'Asia/Tokyo']);
    $segment->refresh();

    expect($method->invoke($service, $segment)[0])->toBe('2026-09-01');
});
