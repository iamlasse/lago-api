<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\Organizations\UpdateService;

beforeEach(function () {
    CurrentContext::reset();
});

function orgUpdateParams(): array
{
    return [
        'legal_name' => 'Foobar',
        'legal_number' => '1234',
        'tax_identification_number' => '2246',
        'email' => 'foo@bar.com',
        'address_line1' => 'Line 1',
        'address_line2' => 'Line 2',
        'state' => 'Foobar',
        'zipcode' => 'FOO1234',
        'city' => 'Foobar',
        'default_currency' => 'EUR',
        'country' => 'fr',
        'authentication_methods' => ['email_password'],
        'billing_configuration' => [
            'invoice_footer' => 'invoice footer',
            'document_locale' => 'fr',
        ],
    ];
}

it('updates the organization and its default billing entity', function () {
    CurrentContext::$organization = Organization::factory()->create();
    $organization = CurrentContext::$organization;

    $result = UpdateService::call(organization: $organization, params: orgUpdateParams());

    expect($result->success())->toBeTrue();

    $organization = $organization->fresh();

    expect($organization->legal_name)->toBe('Foobar')
        ->and($organization->legal_number)->toBe('1234')
        ->and($organization->tax_identification_number)->toBe('2246')
        ->and($organization->email)->toBe('foo@bar.com')
        ->and($organization->address_line1)->toBe('Line 1')
        ->and($organization->address_line2)->toBe('Line 2')
        ->and($organization->state)->toBe('Foobar')
        ->and($organization->zipcode)->toBe('FOO1234')
        ->and($organization->city)->toBe('Foobar')
        ->and($organization->country)->toBe('FR')
        ->and($organization->default_currency)->toBe('EUR')
        ->and($organization->authentication_methods)->toBe(['email_password'])
        ->and($organization->invoice_footer)->toBe('invoice footer')
        ->and($organization->document_locale)->toBe('fr');

    $billingEntity = $organization->defaultBillingEntity;

    expect($billingEntity->legal_name)->toBe('Foobar')
        ->and($billingEntity->legal_number)->toBe('1234')
        ->and($billingEntity->tax_identification_number)->toBe('2246')
        ->and($billingEntity->email)->toBe('foo@bar.com')
        ->and($billingEntity->country)->toBe('FR')
        ->and($billingEntity->default_currency)->toBe('EUR')
        ->and($billingEntity->invoice_footer)->toBe('invoice footer')
        ->and($billingEntity->document_locale)->toBe('fr');
})->group('ledger:svc:Organizations.UpdateService');

it('upcases the document number prefix', function () {
    CurrentContext::$organization = Organization::factory()->create();
    $params = orgUpdateParams();
    $params['document_number_prefix'] = 'abc';

    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeTrue()
        ->and(CurrentContext::$organization->fresh()->document_number_prefix)->toBe('ABC');
});

it('normalizes and rejects invalid slugs', function () {
    CurrentContext::$organization = Organization::factory()->create();
    $params = orgUpdateParams();
    $params['slug'] = '  My-Slug  ';

    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeTrue()
        ->and(CurrentContext::$organization->fresh()->slug)->toBe('my-slug');

    $params['slug'] = 'INVALID SLUG!';
    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['slug'])->not->toBeEmpty();
});

it('rejects a taken or reserved slug', function () {
    CurrentContext::$organization = Organization::factory()->create();
    Organization::factory()->create(['slug' => 'taken-slug']);
    $params = orgUpdateParams();
    $params['slug'] = 'taken-slug';

    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages['slug'])->toBe(['value_already_exist']);

    $params['slug'] = 'customers';
    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeFalse()
        ->and($result->getError()->messages['slug'])->not->toBeEmpty();
});

it('rejects an invalid document number prefix', function () {
    CurrentContext::$organization = Organization::factory()->create();
    $params = orgUpdateParams();
    $params['document_number_prefix'] = 'aaaaaaaaaaaaaaa';

    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['document_number_prefix'])->toBe(['value_is_too_long']);
});

it('rejects an invalid country code', function () {
    CurrentContext::$organization = Organization::factory()->create();
    $params = orgUpdateParams();
    $params['country'] = '---';

    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['country'])->toBe(['not_a_valid_country_code']);
});

it('sanitizes unicode lookalike characters in the email', function () {
    CurrentContext::$organization = Organization::factory()->create();

    $result = UpdateService::call(organization: CurrentContext::$organization, params: [
        'email' => "hello@something\xE2\x80\x93other.com",
    ]);

    expect($result->success())->toBeTrue()
        ->and(CurrentContext::$organization->fresh()->email)->toBe('hello@something-other.com');
});

it('updates the document numbering on organization and billing entity', function () {
    CurrentContext::$organization = Organization::factory()->create();
    $params = ['document_numbering' => 'per_organization'];

    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeTrue()
        ->and(CurrentContext::$organization->fresh()->document_numbering->value)->toBe(1)
        ->and(CurrentContext::$organization->fresh()->defaultBillingEntity->document_numbering->value)
        ->toBe('per_billing_entity');
});

it('rejects an invalid document numbering value', function () {
    CurrentContext::$organization = Organization::factory()->create();
    $params = ['document_numbering' => 'not_existing_document_numbering'];

    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['document_numbering'])->toBe(['value_is_invalid']);
});

it('validates eu tax management against the organization country', function () {
    CurrentContext::$organization = Organization::factory()->create();
    $params = ['eu_tax_management' => true, 'country' => 'fr'];

    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeTrue()
        ->and(CurrentContext::$organization->fresh()->eu_tax_management)->toBeTrue();

    $params = ['eu_tax_management' => true, 'country' => 'us'];
    $result = UpdateService::call(organization: CurrentContext::$organization, params: $params);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['eu_tax_management' => ['org_must_be_in_eu']])
        ->and(CurrentContext::$organization->fresh()->eu_tax_management)->toBeTrue();
});

it('can disable eu tax management outside the eu', function () {
    CurrentContext::$organization = Organization::factory()->create();
    CurrentContext::$organization->update(['country' => 'US', 'eu_tax_management' => true]);

    $result = UpdateService::call(organization: CurrentContext::$organization, params: ['eu_tax_management' => false]);

    expect($result->success())->toBeTrue()
        ->and(CurrentContext::$organization->fresh()->eu_tax_management)->toBeFalse();
});

it('updates the webhook url on the first webhook endpoint', function () {
    CurrentContext::$organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $organization = CurrentContext::$organization;

    $result = UpdateService::call(organization: $organization, params: ['webhook_url' => 'https://example.com/hook']);

    expect($result->success())->toBeTrue()
        ->and($organization->webhookEndpoints()->count())->toBe(1)
        ->and($organization->webhookEndpoints()->first()->webhook_url)->toBe('https://example.com/hook');

    $result = UpdateService::call(organization: $organization, params: ['webhook_url' => 'https://example.com/hook2']);

    expect($organization->webhookEndpoints()->count())->toBe(1)
        ->and($organization->webhookEndpoints()->first()->webhook_url)->toBe('https://example.com/hook2');
});

it('fails with a not found failure when there is no active billing entity', function () {
    CurrentContext::$organization = Organization::factory()->create();
    $organization = CurrentContext::$organization;

    DB::table('billing_entities')
        ->where('organization_id', $organization->id)
        ->update(['archived_at' => now()]);

    $result = UpdateService::call(organization: $organization, params: orgUpdateParams());

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('billing_entity_not_found')
        ->and($organization->fresh()->legal_name)->not->toBe('Foobar');
});
