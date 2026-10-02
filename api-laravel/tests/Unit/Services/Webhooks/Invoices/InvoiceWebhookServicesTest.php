<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Webhook;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use App\Services\Webhooks\Invoices\CreatedService;
use App\Services\Webhooks\Invoices\DraftedService;

/**
 * Port of the "creates webhook" shared example for the invoice builders
 * (spec/services/webhooks/invoices/*_service_spec.rb).
 */
beforeEach(function () {
    Queue::fake();

    $this->organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $this->endpoint = WebhookEndpoint::factory()->forOrganization($this->organization)->create();

    // No InvoiceFactory yet (invoices slice in flight) — insert a minimal row.
    $this->invoice = Invoice::query()->create([
        'organization_id' => $this->organization->id,
        'customer_id' => App\Models\Customer::factory()->for($this->organization)->create()->id,
        'billing_entity_id' => $this->organization->billingEntities()->first()->id,
        'invoice_type' => 1, // subscription
        'status' => 1, // finalized
        'payment_status' => 0,
        'currency' => 'EUR',
        'issuing_date' => now()->toDateString(),
        'fees_amount_cents' => 100,
        'total_amount_cents' => 121,
    ]);
});

it('creates an invoice.created webhook', function () {
    CreatedService::call(object: $this->invoice);

    $webhook = Webhook::query()->latest('created_at')->first();

    expect($webhook->payload['webhook_type'])->toBe('invoice.created')
        ->and($webhook->payload['object_type'])->toBe('invoice')
        ->and($webhook->payload['organization_id'])->toBe($this->organization->id)
        ->and($webhook->payload['invoice']['lago_id'])->toBe($this->invoice->id)
        ->and($webhook->payload['invoice']['status'])->toBe($this->invoice->getRawOriginal('status'))
        ->and($webhook->payload['invoice']['currency'])->toBe($this->invoice->currency)
        ->and($webhook->object_id)->toBe($this->invoice->id)
        ->and($webhook->object_type)->toBe(Invoice::class);

    Queue::assertPushed(App\Jobs\SendHttpWebhookJob::class, 1);
});

it('creates an invoice.drafted webhook', function () {
    DraftedService::call(object: $this->invoice);

    $payload = Webhook::query()->latest('created_at')->first()->payload;

    expect($payload['webhook_type'])->toBe('invoice.drafted')
        ->and($payload['object_type'])->toBe('invoice')
        ->and($payload['invoice']['lago_id'])->toBe($this->invoice->id);
});
