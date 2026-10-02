<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\CurrentContext;
use Database\Factories\TaxFactory;
use App\Serializers\V1\OrganizationSerializer;

beforeEach(function (): void {
    CurrentContext::reset();
});

it('serializes the organization with literal snake_case keys', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $webhookUrls = $organization->webhookEndpoints->pluck('webhook_url')->all();

    $tax = TaxFactory::new()->create(['organization_id' => $organization->id, 'applied_to_organization' => true]);

    $serializer = new OrganizationSerializer($organization, [
        'root_name' => 'organization',
        'includes' => ['taxes'],
    ]);

    $result = json_decode($serializer->toJson(), true)['organization'];

    expect($result['lago_id'])->toBe($organization->id)
        ->and($result['name'])->toBe($organization->name)
        ->and($result['slug'])->toBe($organization->slug)
        ->and($result['default_currency'])->toBe('USD')
        ->and($result['created_at'])->toBe($organization->created_at->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($result['webhook_url'])->toBe($webhookUrls[0] ?? '')
        ->and($result['webhook_urls'])->toBe($webhookUrls)
        ->and($result['country'])->toBe($organization->country)
        ->and($result['email_settings'])->toBe(['invoice.finalized', 'credit_note.created'])
        ->and($result['document_numbering'])->toBe('per_customer')
        ->and($result['document_number_prefix'])->toBe($organization->document_number_prefix)
        ->and($result['finalize_zero_amount_invoice'])->toBeTrue()
        ->and($result['net_payment_term'])->toBe(0)
        ->and($result['billing_configuration'])->toBe([
            'invoice_footer' => null,
            'invoice_grace_period' => 0,
            'document_locale' => 'en',
        ])
        ->and($result['events_store'])->toBe('postgres')
        ->and($result['taxes'])->toHaveCount(1)
        ->and($result['taxes'][0]['lago_id'])->toBe($tax->id);
})->group('ledger:ser:V1.OrganizationSerializer');

it('delegates default_currency and timezone to the default billing entity', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $billingEntity = $organization->defaultBillingEntity;
    $billingEntity->update(['default_currency' => 'EUR', 'timezone' => 'Europe/Paris']);

    $serializer = new OrganizationSerializer($organization->fresh(), ['root_name' => 'organization']);
    $result = json_decode($serializer->toJson(), true)['organization'];

    expect($result['default_currency'])->toBe('EUR')
        ->and($result['timezone'])->toBe('Europe/Paris');
});

it('omits the taxes key when not included', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $serializer = new OrganizationSerializer($organization, ['root_name' => 'organization']);
    $result = json_decode($serializer->toJson(), true)['organization'];

    expect(array_key_exists('taxes', $result))->toBeFalse();
});
