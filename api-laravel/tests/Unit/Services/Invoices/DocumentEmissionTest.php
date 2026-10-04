<?php

declare(strict_types=1);

use App\Models\AddOn;
use App\Models\Customer;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Jobs\Invoices\NotifyJob;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Invoices\GenerateDocumentsJob;
use App\Services\Invoices\CreateOneOffService;

/**
 * Port of the Rails document-emission expectations in
 * spec/services/invoices/create_one_off_service_spec.rb: the one-off
 * creation enqueues Invoices::GenerateDocumentsJob (notify depends on the
 * premium license + billing entity "invoice.finalized" email setting).
 */
function documentEmissionContext(callable $scenario): void
{
    CurrentContext::$source = 'api';

    try {
        $scenario();
    } finally {
        CurrentContext::$source = null;
    }
}

function documentEmissionOneOff(Organization $organization): void
{
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

    CreateOneOffService::call(
        customer: $customer,
        currency: 'EUR',
        fees: [['add_on_code' => $addOn->code, 'unit_amount_cents' => 1000, 'units' => 1]],
        timestamp: now()->startOfMonth()->getTimestamp(),
    );
}

it('enqueues GenerateDocumentsJob after a one-off invoice without notify by default', function (): void {
    documentEmissionContext(function (): void {
        Queue::fake();

        $organization = Organization::factory()->create();

        documentEmissionOneOff($organization);

        Queue::assertPushed(GenerateDocumentsJob::class, fn (GenerateDocumentsJob $job): bool => $job->notify === false);
        Queue::assertNotPushed(NotifyJob::class);
    });
});

it('enqueues GenerateDocumentsJob with notify for premium email settings', function (): void {
    documentEmissionContext(function (): void {
        config(['lago.license' => 'premium-license-token']);
        Queue::fake();

        $organization = Organization::factory()->create();

        $billingEntity = $organization->billingEntities()->first();
        $billingEntity->update(['email_settings' => ['invoice.finalized']]);

        documentEmissionOneOff($organization);

        Queue::assertPushed(GenerateDocumentsJob::class, fn (GenerateDocumentsJob $job): bool => $job->notify === true);

        config(['lago.license' => null]);
    });
});

it('does not notify when the billing entity email setting is absent', function (): void {
    documentEmissionContext(function (): void {
        config(['lago.license' => 'premium-license-token']);
        Queue::fake();

        $organization = Organization::factory()->create();
        $organization->billingEntities()->first()->update(['email_settings' => []]);

        documentEmissionOneOff($organization);

        Queue::assertPushed(GenerateDocumentsJob::class, fn (GenerateDocumentsJob $job): bool => $job->notify === false);

        config(['lago.license' => null]);
    });
});
