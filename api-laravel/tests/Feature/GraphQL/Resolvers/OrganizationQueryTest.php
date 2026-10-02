<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\ApiKey;
use Illuminate\Support\Arr;
use App\Support\Utils\AuthToken;

/**
 * Port of Rails' spec/graphql/resolvers/organization_resolver_spec.rb —
 * `organization` (CurrentOrganizationType) over the frozen SDL.
 *
 * Ledger row: gql:query:organization.
 */
const ORGANIZATION_QUERY = <<<'GQL'
query {
    organization {
        id
        name
        email
        city
        slug
        defaultCurrency
        documentNumbering
        documentNumberPrefix
        netPaymentTerm
        euTaxManagement
        finalizeZeroAmountInvoice
        eventsStore
        featureFlags
        premiumIntegrations
        authenticationMethods
        authenticatedMethod
        accessibleByCurrentSession
        canCreateBillingEntity
        logoUrl
        timezone
        apiKey
        hmacKey
        webhookUrl
        emailSettings
        billingConfiguration {
            id
            documentLocale
            invoiceFooter
            invoiceGracePeriod
        }
        taxes {
            id
        }
    }
}
GQL;

function gqlOrganizationQuery(App\Models\User $user, ?string $organizationId, array $variables = []): Illuminate\Testing\TestResponse
{
    return gqlPost(ORGANIZATION_QUERY, $variables, [
        'Authorization' => 'Bearer '.AuthToken::encode($user, extra: ['login_method' => 'email_password']),
        ...($organizationId !== null ? ['x-lago-organization' => $organizationId] : []),
    ]);
}

it('returns the current organization', function () {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlOrganizationQuery($user, $organization->id);

    $response->assertOk();

    $data = $response->json('data.organization');

    expect($data['id'])->toBe($organization->id)
        ->and($data['name'])->toBe($organization->name)
        ->and($data['slug'])->toBe($organization->slug)
        ->and($data['canCreateBillingEntity'])->toBeTrue()
        ->and($data['eventsStore'])->toBe('postgres')
        ->and($data['logoUrl'])->toBeNull()
        ->and($data['taxes'])->toBe([]);
})->group('ledger:gql:query:organization');

it('resolves the current session, api key, webhook url and billing configuration fields', function () {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $apiKey = ApiKey::factory()->create(['organization_id' => $organization->id]);
    $organization->webhookEndpoints()->create(['webhook_url' => 'https://example.com/hook']);

    $response = gqlOrganizationQuery($user, $organization->id);

    $data = $response->json('data.organization');

    // JWT login method is email_password, part of the org's defaults.
    expect($data['apiKey'])->toBe($apiKey->value)
        ->and($data['hmacKey'])->toBe($organization->refresh()->hmac_key)
        ->and($data['webhookUrl'])->toBe('https://example.com/hook')
        ->and($data['authenticatedMethod'])->toBe('email_password')
        ->and($data['accessibleByCurrentSession'])->toBeTrue()
        ->and($data['authenticationMethods'])->toBe(['email_password', 'google_oauth'])
        ->and($data['billingConfiguration']['documentLocale'])->toBe('en')
        ->and($data['billingConfiguration']['invoiceGracePeriod'])->toBe(0);
})->group('ledger:gql:query:organization');

it('serializes email settings with the enum wire values and filters feature flags', function () {
    $organization = gqlCreateOrganization();
    $organization->email_settings = ['invoice.finalized', 'credit_note.created'];
    $organization->feature_flags = ['order_forms', 'not_a_real_flag'];
    $organization->save();

    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $response = gqlOrganizationQuery($user, $organization->id);

    $data = $response->json('data.organization');

    expect($data['emailSettings'])->toBe(['invoice_finalized', 'credit_note_created'])
        ->and($data['featureFlags'])->toBe(['order_forms']);
})->group('ledger:gql:query:organization');

it('returns unauthorized on organization without a token', function () {
    $response = gqlPost(ORGANIZATION_QUERY);

    $response->assertOk();

    expect($response->json('data.organization'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 'unauthorized',
            'code' => 'unauthorized',
        ]);
})->group('ledger:gql:query:organization');

it('returns forbidden on organization without an organization id', function () {
    $user = gqlCreateUser();

    $response = gqlOrganizationQuery($user, null);

    expect($response->json('data.organization'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Missing organization id')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 'forbidden',
            'code' => 'forbidden',
        ]);
})->group('ledger:gql:query:organization');

it('returns forbidden when the user has no membership in the requested organization', function () {
    $user = gqlCreateUser();
    $other = gqlCreateOrganization('Other Corp');
    gqlCreateMembership(gqlCreateUser('other@example.com'), $other);

    // The AuthenticateUser middleware only switches to organizations the
    // user is a member of, so the foreign id leaves current_organization
    // unset and the RequiredOrganization guard fires.
    $response = gqlOrganizationQuery($user, $other->id);

    expect($response->json('errors.0.message'))->toBe('Missing organization id')
        ->and(Arr::get($response->json('errors.0'), 'extensions.status'))->toBe('forbidden');
})->group('ledger:gql:query:organization');
