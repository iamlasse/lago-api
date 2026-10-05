<?php

declare(strict_types=1);

uses()->group('ledger:svc:Quotes.CreateService',
    'ledger:svc:Quotes.UpdateService',
    'ledger:svc:Quotes.LockService',
    'ledger:svc:QuoteVersions.CreateService',
    'ledger:svc:QuoteVersions.UpdateService',
    'ledger:svc:QuoteVersions.ApproveService',
    'ledger:svc:QuoteVersions.CloneService',
    'ledger:svc:QuoteVersions.VoidService',
    'ledger:svc:QuoteVersions.DealExpiration',
    'ledger:svc:QuoteVersions.Validators',
    'ledger:svc:QuoteVersions.Validators.BaseOrderTypeValidator',
    'ledger:svc:QuoteVersions.Validators.BaseStructuralValidator',
    'ledger:svc:QuoteVersions.Validators.CurrencyValidation',
    'ledger:svc:QuoteVersions.Validators.OneOffValidator',
    'ledger:svc:QuoteVersions.Validators.OneOff.Schema',
    'ledger:svc:QuoteVersions.Validators.OneOff.StructuralValidator',
    'ledger:svc:QuoteVersions.Validators.OneOff.BusinessValidator',
    'ledger:svc:QuoteVersions.Validators.SubscriptionAmendmentValidator',
    'ledger:svc:QuoteVersions.Validators.SubscriptionAmendment.BusinessValidator',
    'ledger:svc:QuoteVersions.Validators.SubscriptionCreationValidator',
    'ledger:svc:QuoteVersions.Validators.SubscriptionCreation.Schema',
    'ledger:svc:QuoteVersions.Validators.SubscriptionCreation.StructuralValidator',
    'ledger:svc:QuoteVersions.Validators.SubscriptionCreation.BusinessValidator',
    'ledger:svc:OrderForms.ExpireService',
    'ledger:svc:OrderForms.Premium');

use App\Models\Plan;
use App\Models\AddOn;
use App\Models\Quote;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\OrderForm;
use App\Models\Organization;
use App\Models\QuoteVersion;
use App\Models\Subscription;
use App\Services\Quotes\CreateService;
use App\Services\Quotes\UpdateService;
use App\Services\OrderForms\ExpireService;
use App\Services\QuoteVersions\VoidService;
use App\Services\QuoteVersions\CloneService;
use App\Services\QuoteVersions\DealExpiration;
use App\Services\QuoteVersions\UpdateService as QuoteVersionUpdateService;

/**
 * Ports of Rails' spec/services/quotes/*, spec/services/quote_versions/*
 * (the create / update / clone / void / deal-expiration scenarios) and
 * spec/services/order_forms/expire_service_spec.rb.
 */
function quotesServiceOrganization(array $attributes = []): Organization
{
    return Organization::factory()->create(array_merge(
        ['feature_flags' => ['order_forms']],
        $attributes,
    ));
}

function withQuotesServiceLicense(callable $scenario): mixed
{
    config(['lago.license' => 'premium-license-token']);

    try {
        return $scenario();
    } finally {
        config(['lago.license' => null]);
    }
}

// -- Quotes::CreateService -------------------------------------------------------

it('creates a quote with its first version and the deal currency', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'currency' => 'USD']);

        $result = CreateService::call(
            organization: $organization,
            customer: $customer,
            params: [
                'order_type' => 'subscription_creation',
                'billing_items' => ['plans' => []],
            ],
        );

        expect($result->success())->toBeTrue();

        $quote = $result->quote;

        expect($quote->order_type)->toBe('subscription_creation')
            ->and($quote->number)->toMatch('/\AQT-\d{4}-\d{4,}\z/')
            ->and($quote->quoteVersions()->count())->toBe(1)
            ->and($quote->quoteVersions()->first()->currency)->toBe('USD')
            ->and($quote->quoteVersions()->first()->status)->toBe('draft');
    });
});

it('falls back to the billing entity currency when the customer has none', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'currency' => null]);

        $result = CreateService::call(
            organization: $organization,
            customer: $customer,
            params: ['order_type' => 'one_off'],
        );

        expect($result->success())->toBeTrue()
            ->and($result->quote->quoteVersions()->first()->currency)
            ->toBe($customer->billingEntity->default_currency);
    });
});

it('requires a subscription for amendments', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);

        $result = CreateService::call(
            organization: $organization,
            customer: $customer,
            params: ['order_type' => 'subscription_amendment'],
        );

        expect($result->failure())->toBeTrue()
            ->and($result->getError()->resource ?? null)->toBe('subscription');
    });
});

it('refuses a subscription outside the deal scope', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $otherCustomer = Customer::factory()->create(['organization_id' => $organization->id]);
        $subscription = Subscription::factory()->forCustomer($otherCustomer)->create();

        $result = CreateService::call(
            organization: $organization,
            customer: $customer,
            subscription: $subscription,
            params: ['order_type' => 'subscription_amendment'],
        );

        expect($result->failure())->toBeTrue()
            ->and($result->getError()->resource ?? null)->toBe('subscription');
    });
});

it('answers forbidden without the premium license', function (): void {
    $organization = quotesServiceOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $result = CreateService::call(
        organization: $organization,
        customer: $customer,
        params: ['order_type' => 'one_off'],
    );

    expect($result->failure())->toBeTrue();
});

// -- Quotes::UpdateService --------------------------------------------------------

it('syncs owners on the quote', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->create();

        $ownerOne = App\Models\User::create(['email' => 'owner-one@example.com']);
        $ownerTwo = App\Models\User::create(['email' => 'owner-two@example.com']);
        App\Models\Membership::create(['organization_id' => $organization->id, 'user_id' => $ownerOne->id, 'status' => 0]);
        App\Models\Membership::create(['organization_id' => $organization->id, 'user_id' => $ownerTwo->id, 'status' => 0]);

        $result = UpdateService::call(quote: $quote, params: ['owners' => [$ownerOne->id, $ownerTwo->id]]);

        expect($result->success())->toBeTrue()
            ->and($quote->owners()->pluck('users.id'))->toHaveCount(2);

        $result = UpdateService::call(quote: $quote, params: ['owners' => [$ownerOne->id]]);

        expect($result->success())->toBeTrue()
            ->and($quote->owners()->pluck('users.id')->map(strval(...))->all())->toBe([$ownerOne->id]);
    });
});

it('refuses unknown owners', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->create();

        $result = UpdateService::call(quote: $quote, params: ['owners' => [Illuminate\Support\Str::uuid()]]);

        expect($result->failure())->toBeTrue()
            ->and($result->getError()->messages ?? [])->toBe(['owners' => ['invalid']]);
    });
});

// -- QuoteVersions::VoidService ------------------------------------------------------

it('refuses an unknown void reason', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->create();
        $version = QuoteVersion::factory()->forQuote($quote)->create();

        $result = VoidService::call(quoteVersion: $version, reason: 'whatever');

        expect($result->failure())->toBeTrue()
            ->and($result->getError()->messages ?? [])->toBe(['void_reason' => ['invalid']]);
    });
});

it('refuses to void an approved version with superseded', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->create();
        $version = QuoteVersion::factory()->forQuote($quote)->approved()->create();

        $result = VoidService::call(quoteVersion: $version, reason: 'superseded');

        expect($result->failure())->toBeTrue()
            ->and($result->getError()->messages ?? [])->toBe(['status' => ['not_voidable']]);
    });
});

it('cascades a void onto an approved version', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->create();
        $version = QuoteVersion::factory()->forQuote($quote)->approved()->create();

        $result = VoidService::call(quoteVersion: $version, reason: 'cascade_of_voided');

        expect($result->success())->toBeTrue()
            ->and($version->refresh()->status)->toBe('voided')
            ->and($version->approved_at)->toBeNull();
    });
});

// -- QuoteVersions::CloneService ------------------------------------------------------

it('clones a voided version by voiding the active draft first', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->create();
        // The source: an older, voided version carrying a stale snapshot; the
        // active draft is the one that gets superseded.
        $version = QuoteVersion::factory()->forQuote($quote)->voided('superseded')->withOneOffBillingItems()->create();
        $version->mention_variables = ['customer_name' => 'Foo'];
        $version->save();

        $draft = QuoteVersion::factory()->forQuote($quote)->create(['currency' => 'EUR']);

        $result = CloneService::call(quoteVersion: $version);

        expect($result->success())->toBeTrue();

        $clone = $result->quote_version;

        expect($clone->id)->not->toBe($version->id)
            ->and($clone->status)->toBe('draft')
            ->and($clone->sequential_id)->toBe(3) // versions 1 and 2 exist; the clone is v3
            ->and($clone->mention_variables)->toBeNull()
            ->and($clone->billing_items)->toBe($version->billing_items)
            // The active draft was voided as superseded to free the slot.
            ->and($draft->refresh()->status)->toBe('voided')
            ->and($draft->void_reason)->toBe('superseded');
    });
});

// -- QuoteVersions::DealExpiration -----------------------------------------------------

it('bounds the deal by the earliest plan end date or wallet expiration', function (): void {
    $organization = quotesServiceOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->create();
    $version = QuoteVersion::factory()->forQuote($quote)->create([
        'billing_items' => [
            'plans' => [
                ['payload' => ['endDate' => '2030-06-15T00:00:00Z']],
            ],
            'walletCredits' => [
                ['payload' => ['expirationAt' => '2029-01-01T00:00:00Z']],
            ],
        ],
    ]);

    expect(DealExpiration::earliest($version))->toBe('2029-01-01')
        // Only a date the deal no longer covers is refused — the boundary day
        // included (the execution flow requires the ending date to be
        // strictly after the day it runs).
        ->and(DealExpiration::covers($version, '2028-12-31T00:00:00Z'))->toBeTrue()
        ->and(DealExpiration::covers($version, '2029-01-01'))->toBeFalse()
        ->and(DealExpiration::covers($version, '2029-01-02'))->toBeFalse()
        // A blank value is covered.
        ->and(DealExpiration::covers($version, null))->toBeTrue();
});

it('treats a one_off deal as unbounded', function (): void {
    $organization = quotesServiceOrganization();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $quote = Quote::factory()->forCustomer($customer)->orderType('one_off')->create();
    $version = QuoteVersion::factory()->forQuote($quote)->withOneOffBillingItems()->create();

    expect(DealExpiration::earliest($version))->toBeNull()
        ->and(DealExpiration::covers($version, '2999-01-01'))->toBeTrue();
});

// -- QuoteVersions::UpdateService (currency realignment) ---------------------------------

it('realigns the payload currency overrides onto the deal currency', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $plan = Plan::factory()->create(['organization_id' => $organization->id, 'amount_currency' => 'USD']);
        $coupon = Coupon::factory()->create([
            'organization_id' => $organization->id,
            'coupon_type' => 'fixed_amount',
            'amount_currency' => 'USD',
        ]);

        $quote = Quote::factory()->forCustomer($customer)->create();
        $version = QuoteVersion::factory()->forQuote($quote)->create([
            'currency' => 'EUR',
            'billing_items' => [
                'plans' => [
                    ['id' => $plan->id, 'type' => 'plan', 'payload' => ['code' => $plan->code]],
                ],
                'coupons' => [
                    [
                        'id' => $coupon->id,
                        'localId' => 'c1',
                        'type' => 'coupon',
                        'payload' => ['code' => $coupon->code],
                    ],
                ],
            ],
        ]);

        $result = QuoteVersionUpdateService::call(
            quoteVersion: $version,
            params: ['currency' => 'EUR', 'billing_items' => $version->billing_items],
        );

        expect($result->success())->toBeTrue();

        $items = $version->refresh()->billing_items;

        // The catalog plans are USD, the deal EUR: the overrides now state the
        // deal currency; the payload values are not converted.
        expect($items['plans'][0]['overrides']['amountCurrency'])->toBe('EUR')
            ->and($items['coupons'][0]['overrides']['amountCurrency'])->toBe('EUR');
    });
});

it('refuses a currency change on an amendment', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $subscription = Subscription::factory()->forCustomer($customer)->create();
        $quote = Quote::factory()->forCustomer($customer)->orderType('subscription_amendment')->forSubscription($subscription)->create();
        $version = QuoteVersion::factory()->forQuote($quote)->create(['currency' => 'EUR']);

        $result = QuoteVersionUpdateService::call(
            quoteVersion: $version,
            params: ['currency' => 'USD'],
        );

        expect($result->failure())->toBeTrue()
            ->and($result->getError()->messages ?? [])->toBe(['currency' => ['not_supported_for_order_type']]);
    });
});

// -- OrderForms::ExpireService -------------------------------------------------------------

it('expires a generated order form and cascades onto the version', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->create();
        $version = QuoteVersion::factory()->forQuote($quote)->approved()->withOneOffBillingItems()->create();
        $orderForm = OrderForm::factory()
            ->forCustomer($customer)
            ->forQuoteVersion($version)
            ->withExpiresAt(now()->subDay())
            ->create();

        $result = ExpireService::call(orderForm: $orderForm);

        expect($result->success())->toBeTrue()
            ->and($orderForm->refresh()->status)->toBe('expired')
            ->and($orderForm->void_reason)->toBe('expired')
            ->and($version->refresh()->status)->toBe('voided')
            ->and($version->void_reason)->toBe('cascade_of_expired');
    });
});

it('answers forbidden expiry over a voided form', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->create();
        $version = QuoteVersion::factory()->forQuote($quote)->approved()->withOneOffBillingItems()->create();
        $orderForm = OrderForm::factory()
            ->forCustomer($customer)
            ->forQuoteVersion($version)
            ->voided()
            ->create();

        $result = ExpireService::call(orderForm: $orderForm);

        expect($result->failure())->toBeTrue()
            ->and($result->getError()->code ?? null)->toBe('order_form_is_voided');
    });
});

it('is idempotent over an already expired form', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->create();
        $version = QuoteVersion::factory()->forQuote($quote)->approved()->withOneOffBillingItems()->create();
        $orderForm = OrderForm::factory()
            ->forCustomer($customer)
            ->forQuoteVersion($version)
            ->expired()
            ->create();

        $result = ExpireService::call(orderForm: $orderForm);

        expect($result->success())->toBeTrue()
            ->and($result->order_form->id)->toBe($orderForm->id);
    });
});

// -- One-off structural + business validators -------------------------------------------------

it('reports one_off structural errors with the payload pointers', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $quote = Quote::factory()->forCustomer($customer)->orderType('one_off')->create();

        $version = QuoteVersion::factory()->forQuote($quote)->create([
            'currency' => 'EUR',
            'billing_items' => [
                'addOns' => [
                    [
                        'id' => Illuminate\Support\Str::uuid(),
                        'localId' => 'l1',
                        'type' => 'add_on',
                        'payload' => ['units' => 1],
                        'unknown' => 'x',
                    ],
                ],
            ],
        ]);

        $result = App\Services\QuoteVersions\ApproveService::call(quoteVersion: $version);

        expect($result->failure())->toBeTrue();

        $messages = $result->getError()->messages;

        expect($messages['billing_items.addOns.0.payload.code'] ?? [])->toBe(['value_is_mandatory'])
            ->and($messages['billing_items.addOns.0.unknown'] ?? [])->toBe(['unsupported_key']);
    });
});

it('validates the one_off add-on service period', function (): void {
    withQuotesServiceLicense(function (): void {
        $organization = quotesServiceOrganization();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);

        $quote = Quote::factory()->forCustomer($customer)->orderType('one_off')->create();
        $version = QuoteVersion::factory()->forQuote($quote)->create([
            'currency' => 'EUR',
            'billing_items' => [
                'addOns' => [
                    [
                        'id' => $addOn->id,
                        'localId' => 'l1',
                        'type' => 'add_on',
                        'payload' => [
                            'code' => $addOn->code,
                            'units' => 1,
                            'unitAmountCents' => 100,
                            'totalAmountCents' => 100,
                            'fromDatetime' => '2030-06-02T00:00:00Z',
                            'toDatetime' => '2030-06-01T00:00:00Z',
                        ],
                    ],
                ],
            ],
        ]);

        $result = App\Services\QuoteVersions\ApproveService::call(quoteVersion: $version);

        expect($result->failure())->toBeTrue()
            ->and($result->getError()->messages['billing_items.addOns.0.payload.fromDatetime'] ?? [])
            ->toBe(['invalid_date_range']);
    });
});
