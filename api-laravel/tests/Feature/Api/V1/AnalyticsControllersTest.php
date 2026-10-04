<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

/**
 * Ports of the Rails request specs for the five raw-SQL analytics endpoints
 * (spec/requests/api/v1/analytics/*): GET /api/v{1,2}/analytics/
 * {gross_revenue,invoiced_usage,invoice_collection,mrr,overdue_balance},
 * served by Api::V1::Analytics::*Controller over the Analytics::* models
 * (raw SQL on the primary connection, cached 4h). Premium gating lives in
 * the services: invoiced_usage / invoice_collection / mrr answer
 * "feature_unavailable" without a license; gross_revenue and
 * overdue_balance do not.
 */
uses()->group(
    'ledger:rest:GET:/api/v1/analytics/gross_revenue',
    'ledger:rest:GET:/api/v2/analytics/gross_revenue',
    'ledger:rest:GET:/api/v1/analytics/invoiced_usage',
    'ledger:rest:GET:/api/v2/analytics/invoiced_usage',
    'ledger:rest:GET:/api/v1/analytics/invoice_collection',
    'ledger:rest:GET:/api/v2/analytics/invoice_collection',
    'ledger:rest:GET:/api/v1/analytics/mrr',
    'ledger:rest:GET:/api/v2/analytics/mrr',
    'ledger:rest:GET:/api/v1/analytics/overdue_balance',
    'ledger:rest:GET:/api/v2/analytics/overdue_balance',
);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->apiKey = $this->organization->apiKeys()->first();

    Cache::flush();
});

function analyticsFinalizedInvoice(Organization $organization, array $attributes = []): Invoice
{
    $customer = Customer::factory()->forOrganization($organization)->create();

    return Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => App\Enums\InvoiceStatus::Finalized,
        'total_amount_cents' => 1000,
        'currency' => 'EUR',
        ...$attributes,
    ]);
}

it('serves the gross revenue series for the current month', function (): void {
    analyticsFinalizedInvoice($this->organization, ['total_amount_cents' => 1000]);

    $this->getJson(
        '/api/v1/analytics/gross_revenue?currency=eur',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->has('gross_revenues', 1)
                ->where('gross_revenues.0.amount_cents', 1000)
                ->where('gross_revenues.0.currency', 'EUR')
                ->where('gross_revenues.0.invoices_count', 1)
                ->where('gross_revenues.0.month', now('UTC')->startOfMonth()->format('Y-m-d\TH:i:s.v\Z'));
        });
});

it('serves the gross revenue without a premium license (no gate on this endpoint)', function (): void {
    analyticsFinalizedInvoice($this->organization);

    $this->getJson(
        '/api/v2/analytics/gross_revenue',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonCount(1, 'gross_revenues');
});

it('requires the analytic read api permission on gross_revenue', function (): void {
    config(['lago.license' => 'premium-license-token']);

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $this->organization->id],
    );
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['customers' => ['read']]), $this->apiKey->id],
    );

    $this->getJson(
        '/api/v1/analytics/gross_revenue',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'read_action_not_allowed_for_analytic',
        ]);
});

it('filters gross revenue by external customer id', function (): void {
    $invoice = analyticsFinalizedInvoice($this->organization);

    $this->getJson(
        '/api/v1/analytics/gross_revenue?external_customer_id=unknown-customer',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )->assertOk()->assertJsonCount(0, 'gross_revenues');

    $this->getJson(
        '/api/v1/analytics/gross_revenue?external_customer_id='.$invoice->customer->external_id,
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )->assertOk()->assertJsonCount(1, 'gross_revenues');
});

it('answers feature_unavailable on invoiced_usage without a premium license', function (): void {
    $this->getJson(
        '/api/v1/analytics/invoiced_usage',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'feature_unavailable',
        ]);
});

it('serves invoiced_usage with a premium license', function (): void {
    config(['lago.license' => 'premium-license-token']);

    $this->getJson(
        '/api/v1/analytics/invoiced_usage',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )->assertOk()->assertJsonCount(0, 'invoiced_usages');
});

it('answers feature_unavailable on invoice_collection without a premium license', function (): void {
    $this->getJson(
        '/api/v1/analytics/invoice_collection',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'feature_unavailable',
        ]);
});

it('serves the invoice collection per payment status', function (): void {
    config(['lago.license' => 'premium-license-token']);

    analyticsFinalizedInvoice($this->organization);

    $this->getJson(
        '/api/v2/analytics/invoice_collection',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->has('invoice_collections', 1)
                ->where('invoice_collections.0.invoices_count', 1)
                ->where('invoice_collections.0.amount_cents', 1000)
                ->where('invoice_collections.0.payment_status', 'pending')
                ->where('invoice_collections.0.currency', 'EUR');
        });
});

it('answers feature_unavailable on mrr without a premium license', function (): void {
    $this->getJson(
        '/api/v1/analytics/mrr',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'feature_unavailable',
        ]);
});

it('serves the mrr series with a premium license', function (): void {
    config(['lago.license' => 'premium-license-token']);

    $this->getJson(
        '/api/v2/analytics/mrr',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonCount(1, 'mrrs');
});

it('serves the overdue balance without a premium license (no gate on this endpoint)', function (): void {
    // The overdue month must fall inside the all_months window (which starts
    // at the organization's creation month).
    $invoice = analyticsFinalizedInvoice($this->organization, [
        'payment_overdue' => true,
        'payment_due_date' => now('UTC')->subDays(2)->toDateString(),
    ]);

    $this->getJson(
        '/api/v1/analytics/overdue_balance',
        ['Authorization' => 'Bearer '.$this->apiKey->value],
    )
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($invoice): void {
            $json->has('overdue_balances', 1)
                ->where('overdue_balances.0.amount_cents', 1000)
                ->where('overdue_balances.0.currency', 'EUR')
                ->where('overdue_balances.0.lago_invoice_ids', [$invoice->id]);
        });
});
