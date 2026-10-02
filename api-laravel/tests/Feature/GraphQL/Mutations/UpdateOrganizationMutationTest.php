<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\BillingEntity;

/**
 * Port of Rails' spec/graphql/mutations/organizations/update_spec.rb —
 * updateOrganization over the frozen SDL.
 *
 * Ledger row: gql:mutation:updateOrganization.
 */

/**
 * Rails creates the default billing entity with the organization
 * (Organizations::CreateService); the frozen-schema port creates it
 * explicitly in the fixture.
 */
function gqlCreateOrganizationWithBillingEntity(string $name = 'Acme Corp'): object
{
    $organization = gqlCreateOrganization($name);
    BillingEntity::factory()->create(['organization_id' => $organization->id]);

    return $organization->refresh();
}
const UPDATE_ORGANIZATION_MUTATION = <<<'GQL'
mutation($input: UpdateOrganizationInput!) {
    updateOrganization(input: $input) {
        id
        legalNumber
        legalName
        taxIdentificationNumber
        email
        addressLine1
        addressLine2
        state
        zipcode
        city
        country
        defaultCurrency
        netPaymentTerm
        documentNumbering
        documentNumberPrefix
        euTaxManagement
        finalizeZeroAmountInvoice
        webhookUrl
        billingConfiguration {
            invoiceFooter
            invoiceGracePeriod
            documentLocale
        }
    }
}
GQL;

it('updates the organization', function (): void {
    $organization = gqlCreateOrganizationWithBillingEntity();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(
        UPDATE_ORGANIZATION_MUTATION,
        ['input' => [
            'legalNumber' => '1234',
            'legalName' => 'Foobar',
            'taxIdentificationNumber' => '2246',
            'email' => 'foo@bar.com',
            'addressLine1' => 'Line 1',
            'addressLine2' => 'Line 2',
            'netPaymentTerm' => 10,
            'state' => 'Foobar',
            'zipcode' => 'FOO1234',
            'city' => 'Foobar',
            'country' => 'FR',
            'defaultCurrency' => 'EUR',
            'euTaxManagement' => true,
            'webhookUrl' => 'https://app.test.dev',
            'documentNumberPrefix' => 'ORG-2',
            'finalizeZeroAmountInvoice' => false,
            'billingConfiguration' => [
                'invoiceFooter' => 'invoice footer',
                'documentLocale' => 'fr',
            ],
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $data = $response->json('data.updateOrganization');

    expect($data['id'])->toBe($organization->id)
        ->and($data['legalNumber'])->toBe('1234')
        ->and($data['legalName'])->toBe('Foobar')
        ->and($data['taxIdentificationNumber'])->toBe('2246')
        ->and($data['email'])->toBe('foo@bar.com')
        ->and($data['addressLine1'])->toBe('Line 1')
        ->and($data['netPaymentTerm'])->toBe(10)
        ->and($data['country'])->toBe('FR')
        ->and($data['defaultCurrency'])->toBe('EUR')
        ->and($data['euTaxManagement'])->toBeTrue()
        ->and($data['webhookUrl'])->toBe('https://app.test.dev')
        ->and($data['documentNumberPrefix'])->toBe('ORG-2')
        ->and($data['finalizeZeroAmountInvoice'])->toBeFalse()
        ->and($data['billingConfiguration']['invoiceFooter'])->toBe('invoice footer')
        ->and($data['billingConfiguration']['documentLocale'])->toBe('fr')
        ->and($data['billingConfiguration']['invoiceGracePeriod'])->toBe(0);

    expect($organization->refresh()->city)->toBe('Foobar');
})->group('ledger:gql:mutation:updateOrganization');

it('returns the validation error envelope for eu tax management outside the eu', function (): void {
    $organization = gqlCreateOrganizationWithBillingEntity();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlPost(
        UPDATE_ORGANIZATION_MUTATION,
        ['input' => ['country' => 'US', 'euTaxManagement' => true]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.updateOrganization'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Unprocessable Entity')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 422,
            'code' => 'unprocessable_entity',
            'details' => ['euTaxManagement' => ['org_must_be_in_eu']],
        ]);
})->group('ledger:gql:mutation:updateOrganization');

it('returns forbidden without an organization id', function (): void {
    $user = gqlCreateUser();

    $response = gqlPost(
        UPDATE_ORGANIZATION_MUTATION,
        ['input' => ['city' => 'Nowhere']],
        gqlAuthHeaders($user),
    );

    expect($response->json('errors.0.message'))->toBe('Missing organization id')
        ->and($response->json('errors.0.extensions.status'))->toBe('forbidden');
})->group('ledger:gql:mutation:updateOrganization');
