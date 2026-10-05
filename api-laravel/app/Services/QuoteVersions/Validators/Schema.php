<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions\Validators;

use App\Enums\FeeType;
use App\Models\Charge;
use App\Enums\CouponType;
use App\Models\FixedCharge;
use App\Models\Subscription;
use App\Enums\CouponFrequency;
use App\Services\Validators\Currencies;

/**
 * Port of Rails' QuoteVersions::Validators::SubscriptionCreation::Schema
 * (app/services/quote_versions/validators/subscription_creation/schema.rb)
 * — the billing_items payload shapes, expressed as the same constraints the
 * JSON schema states. Rails runs them through JSONSchemer; the port ships a
 * hand-rolled evaluator (StructuralValidator) over the identical
 * definitions, so the error fields and codes match exactly.
 *
 * Definition keywords supported: type, required, properties,
 * additionalProperties (the "unsupported_key" reject), enum, const, format
 * (uuid / date-time), minLength, minimum, exclusiveMinimum, minItems,
 * maxItems and items. The x-error member carries the error code per failing
 * keyword, exactly like the frozen schemas.
 */
class Schema
{
    /** Rails: CURRENCIES = Currencies::ACCEPTED_CURRENCIES.keys. */
    protected const CURRENCIES = null; // resolved lazily, see currencies().

    /** Rails: RULE_TRIGGERS (RecurringTransactionRule). */
    protected const RULE_TRIGGERS = ['interval', 'threshold'];

    /** Rails: RULE_INTERVALS. */
    protected const RULE_INTERVALS = ['weekly', 'monthly', 'quarterly', 'yearly'];

    /** Rails: RULE_METHODS. */
    protected const RULE_METHODS = ['fixed', 'target'];

    /**
     * The definition for the given scope (Rails: SCHEMERS.fetch(scope)).
     *
     * @return array<string, mixed>
     */
    public static function definition(string $scope): array
    {
        return $scope === 'approve'
            ? static::approveDefinition()
            : static::updateDefinition();
    }

    /** @return list<string> */
    protected static function currencies(): array
    {
        return Currencies::list();
    }

    /**
     * Rails: UPDATE_DEFINITION — the subscription_creation payload. Also the
     * schema an amendment validates against (reused as is).
     *
     * @return array<string, mixed>
     */
    protected static function updateDefinition(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
            'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
            'properties' => [
                'plans' => [
                    'type' => 'array',
                    'x-error' => ['type' => 'invalid_type', 'minItems' => 'invalid_count'],
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
                        'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                        'required' => ['id', 'type', 'payload'],
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                                'format' => 'uuid',
                                'x-error' => ['type' => 'invalid_type', 'format' => 'invalid_format'],
                            ],
                            'localId' => [
                                'type' => ['string', 'null'],
                                'minLength' => 1,
                                'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                            ],
                            'type' => [
                                'type' => 'string',
                                'const' => 'plan',
                                'x-error' => ['type' => 'invalid_type', 'const' => 'invalid_value'],
                            ],
                            // Catalog snapshot: free-form, except the keys the
                            // execution flow consumes. startDate/endDate carry no
                            // format on purpose — the business validator applies the
                            // same ISO 8601 check as Subscriptions::ValidateService.
                            'payload' => [
                                'type' => 'object',
                                'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                                'properties' => [
                                    'code' => [
                                        'type' => 'string',
                                        'minLength' => 1,
                                        'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                                    ],
                                    'subscriptionExternalId' => [
                                        'type' => ['string', 'null'],
                                        'minLength' => 1,
                                        'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                                    ],
                                    'subscriptionName' => [
                                        'type' => ['string', 'null'],
                                        'minLength' => 1,
                                        'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                                    ],
                                    'billingTime' => [
                                        'type' => ['string', 'null'],
                                        'enum' => [...Subscription::BILLING_TIME, null],
                                        'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                                    ],
                                    'startDate' => [
                                        'type' => ['string', 'null'],
                                        'x-error' => ['type' => 'invalid_type'],
                                    ],
                                    'endDate' => [
                                        'type' => ['string', 'null'],
                                        'x-error' => ['type' => 'invalid_type'],
                                    ],
                                    'paymentMethodId' => [
                                        'type' => ['string', 'null'],
                                        'format' => 'uuid',
                                        'x-error' => ['type' => 'invalid_type', 'format' => 'invalid_format'],
                                    ],
                                    // Charge overrides are keyed by billableMetricCode while
                                    // Plans::OverrideService keys by charge id, so the id is
                                    // resolved through this snapshot.
                                    'charges' => [
                                        'type' => ['array', 'null'],
                                        'x-error' => ['type' => 'invalid_type'],
                                        'items' => [
                                            'type' => 'object',
                                            'x-error' => ['type' => 'invalid_type'],
                                            'properties' => [
                                                'id' => [
                                                    'type' => 'string',
                                                    'format' => 'uuid',
                                                    'x-error' => ['type' => 'invalid_type', 'format' => 'invalid_format'],
                                                ],
                                                'billableMetric' => [
                                                    'type' => 'object',
                                                    'x-error' => ['type' => 'invalid_type'],
                                                    'properties' => [
                                                        'code' => [
                                                            'type' => 'string',
                                                            'minLength' => 1,
                                                            'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                                                        ],
                                                    ],
                                                ],
                                                'chargeModel' => [
                                                    'type' => ['string', 'null'],
                                                    'enum' => [...Charge::CHARGE_MODELS, null],
                                                    'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'fixedCharges' => [
                                        'type' => ['array', 'null'],
                                        'x-error' => ['type' => 'invalid_type'],
                                        'items' => [
                                            'type' => 'object',
                                            'x-error' => ['type' => 'invalid_type'],
                                            'properties' => [
                                                'id' => [
                                                    'type' => 'string',
                                                    'format' => 'uuid',
                                                    'x-error' => ['type' => 'invalid_type', 'format' => 'invalid_format'],
                                                ],
                                                'addOn' => [
                                                    'type' => 'object',
                                                    'x-error' => ['type' => 'invalid_type'],
                                                    'properties' => [
                                                        'code' => [
                                                            'type' => 'string',
                                                            'minLength' => 1,
                                                            'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                                                        ],
                                                    ],
                                                ],
                                                'chargeModel' => [
                                                    'type' => ['string', 'null'],
                                                    'enum' => [...FixedCharge::CHARGE_MODELS, null],
                                                    'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'overrides' => self::planOverridesDefinition(),
                        ],
                    ],
                ],
                'coupons' => self::couponsDefinition(),
                'walletCredits' => self::walletCreditsDefinition(),
            ],
        ];
    }

    /**
     * Rails: APPROVE_DEFINITION — update plus the mandatory sections and the
     * stricter required keys approval demands.
     *
     * @return array<string, mixed>
     */
    protected static function approveDefinition(): array
    {
        $schema = self::updateDefinition();

        $schema['required'] = ['plans'];

        $plans = &$schema['properties']['plans'];
        $plans['minItems'] = 1;
        $plans['items']['properties']['payload']['required'] = ['code'];

        // An override is resolved through its snapshot entry, by code then by
        // id, so an approved entry must carry both.
        $chargeSnapshot = &$plans['items']['properties']['payload']['properties']['charges']['items'];
        $chargeSnapshot['required'] = ['id'];
        $chargeSnapshot['x-error']['required'] = 'value_is_mandatory';
        $chargeSnapshot['properties']['billableMetric']['required'] = ['code'];
        $chargeSnapshot['properties']['billableMetric']['x-error']['required'] = 'value_is_mandatory';

        $fixedChargeSnapshot = &$plans['items']['properties']['payload']['properties']['fixedCharges']['items'];
        $fixedChargeSnapshot['required'] = ['id'];
        $fixedChargeSnapshot['x-error']['required'] = 'value_is_mandatory';
        $fixedChargeSnapshot['properties']['addOn']['required'] = ['code'];
        $fixedChargeSnapshot['properties']['addOn']['x-error']['required'] = 'value_is_mandatory';

        $schema['properties']['coupons']['items']['properties']['payload']['required'] = ['code', 'type'];

        $walletCreditPayload = &$schema['properties']['walletCredits']['items']['properties']['payload'];
        $walletCreditPayload['required'] = ['paidCredits', 'grantedCredits', 'rateAmount'];
        foreach ($walletCreditPayload['required'] as $key) {
            $walletCreditPayload['properties'][$key]['type'] = 'string';
        }

        // Wallets::ValidateService rejects more than one rule per wallet, so
        // approving two is approving something that cannot be created.
        $recurringRules = &$walletCreditPayload['properties']['recurringTransactionRules'];
        $recurringRules['maxItems'] = 1;
        $recurringRules['x-error']['maxItems'] = 'invalid_count';

        $rules = &$recurringRules['items'];
        $rules['required'] = ['trigger'];
        $rules['properties']['trigger']['type'] = 'string';
        $rules['properties']['trigger']['enum'] = self::RULE_TRIGGERS;

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function planOverridesDefinition(): array
    {
        return [
            'type' => ['object', 'null'],
            'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
            'x-error' => ['type' => 'invalid_type'],
            'properties' => [
                'amountCents' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 0,
                    'x-error' => ['type' => 'invalid_type', 'minimum' => 'invalid_value'],
                ],
                // Plans::OverrideService reprices the duplicated plan in this
                // currency — how a catalog plan is quoted in the deal currency.
                'amountCurrency' => [
                    'type' => ['string', 'null'],
                    'enum' => [...self::currencies(), null],
                    'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_currency'],
                ],
                'invoiceDisplayName' => [
                    'type' => ['string', 'null'],
                    'minLength' => 1,
                    'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                ],
                'name' => [
                    'type' => ['string', 'null'],
                    'minLength' => 1,
                    'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                ],
                'description' => [
                    'type' => ['string', 'null'],
                    'minLength' => 1,
                    'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                ],
                'trialPeriod' => [
                    'type' => ['number', 'null'],
                    'minimum' => 0,
                    'x-error' => ['type' => 'invalid_type', 'minimum' => 'invalid_value'],
                ],
                'minimumCommitment' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
                    'x-error' => ['type' => 'invalid_type'],
                    'properties' => [
                        'amountCents' => [
                            'type' => ['integer', 'null'],
                            'exclusiveMinimum' => 0,
                            'x-error' => ['type' => 'invalid_type', 'exclusiveMinimum' => 'invalid_value'],
                        ],
                        'invoiceDisplayName' => [
                            'type' => ['string', 'null'],
                            'minLength' => 1,
                            'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                        ],
                    ],
                ],
                'usageThresholds' => [
                    'type' => ['array', 'null'],
                    'x-error' => ['type' => 'invalid_type'],
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
                        'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                        'required' => ['amountCents'],
                        'properties' => [
                            'amountCents' => [
                                'type' => 'integer',
                                'exclusiveMinimum' => 0,
                                'x-error' => ['type' => 'invalid_type', 'exclusiveMinimum' => 'invalid_value'],
                            ],
                            'recurring' => [
                                'type' => ['boolean', 'null'],
                                'x-error' => ['type' => 'invalid_type'],
                            ],
                            'thresholdDisplayName' => [
                                'type' => ['string', 'null'],
                                'minLength' => 1,
                                'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                            ],
                        ],
                    ],
                ],
                'charges' => [
                    'type' => ['array', 'null'],
                    'x-error' => ['type' => 'invalid_type'],
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
                        'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                        'required' => ['billableMetricCode'],
                        'properties' => [
                            'billableMetricCode' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                            ],
                            // NOTE: chargeModel is stored for the execution flow to
                            // consume; properties is deliberately only type-checked —
                            // its per-model shape is validated where the override is
                            // applied, not here.
                            'chargeModel' => [
                                'type' => ['string', 'null'],
                                'enum' => [...Charge::CHARGE_MODELS, null],
                                'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                            ],
                            'properties' => [
                                'type' => ['object', 'null'],
                                'x-error' => ['type' => 'invalid_type'],
                            ],
                            'minAmountCents' => [
                                'type' => ['integer', 'null'],
                                'minimum' => 0,
                                'x-error' => ['type' => 'invalid_type', 'minimum' => 'invalid_value'],
                            ],
                            'invoiceDisplayName' => [
                                'type' => ['string', 'null'],
                                'minLength' => 1,
                                'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                            ],
                        ],
                    ],
                ],
                'fixedCharges' => [
                    'type' => ['array', 'null'],
                    'x-error' => ['type' => 'invalid_type'],
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
                        'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                        'required' => ['addOnCode'],
                        'properties' => [
                            'addOnCode' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                            ],
                            'units' => [
                                'type' => ['string', 'null'],
                                'minLength' => 1,
                                'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                            ],
                            'properties' => [
                                'type' => ['object', 'null'],
                                'x-error' => ['type' => 'invalid_type'],
                            ],
                            'invoiceDisplayName' => [
                                'type' => ['string', 'null'],
                                'minLength' => 1,
                                'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function couponsDefinition(): array
    {
        $couponTypes = CouponType::options();
        $couponFrequencies = CouponFrequency::options();

        return [
            'type' => 'array',
            'x-error' => ['type' => 'invalid_type'],
            'items' => [
                'type' => 'object',
                'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
                'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                'required' => ['id', 'localId', 'type', 'payload'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'format' => 'uuid',
                        'x-error' => ['type' => 'invalid_type', 'format' => 'invalid_format'],
                    ],
                    'localId' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                    ],
                    'type' => [
                        'type' => 'string',
                        'const' => 'coupon',
                        'x-error' => ['type' => 'invalid_type', 'const' => 'invalid_value'],
                    ],
                    'payload' => [
                        'type' => 'object',
                        'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                        'properties' => [
                            'code' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                            ],
                            'type' => [
                                'type' => 'string',
                                'enum' => $couponTypes,
                                'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                            ],
                            'amountCents' => [
                                'type' => ['integer', 'null'],
                                'minimum' => 0,
                                'x-error' => ['type' => 'invalid_type', 'minimum' => 'invalid_value'],
                            ],
                            'currency' => [
                                'type' => ['string', 'null'],
                                'enum' => [...self::currencies(), null],
                                'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_currency'],
                            ],
                            'percentageRate' => [
                                'type' => ['number', 'null'],
                                'exclusiveMinimum' => 0,
                                'x-error' => ['type' => 'invalid_type', 'exclusiveMinimum' => 'invalid_value'],
                            ],
                            'frequency' => [
                                'type' => ['string', 'null'],
                                'enum' => [...$couponFrequencies, null],
                                'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                            ],
                            'frequencyDuration' => [
                                'type' => ['integer', 'null'],
                                'exclusiveMinimum' => 0,
                                'x-error' => ['type' => 'invalid_type', 'exclusiveMinimum' => 'invalid_value'],
                            ],
                        ],
                    ],
                    'overrides' => [
                        'type' => ['object', 'null'],
                        'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
                        'x-error' => ['type' => 'invalid_type'],
                        'properties' => [
                            'amountCents' => [
                                'type' => ['integer', 'null'],
                                'minimum' => 0,
                                'x-error' => ['type' => 'invalid_type', 'minimum' => 'invalid_value'],
                            ],
                            'amountCurrency' => [
                                'type' => ['string', 'null'],
                                'enum' => [...self::currencies(), null],
                                'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_currency'],
                            ],
                            'percentageRate' => [
                                'type' => ['number', 'null'],
                                'exclusiveMinimum' => 0,
                                'x-error' => ['type' => 'invalid_type', 'exclusiveMinimum' => 'invalid_value'],
                            ],
                            'frequency' => [
                                'type' => ['string', 'null'],
                                'enum' => [...$couponFrequencies, null],
                                'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                            ],
                            'frequencyDuration' => [
                                'type' => ['integer', 'null'],
                                'exclusiveMinimum' => 0,
                                'x-error' => ['type' => 'invalid_type', 'exclusiveMinimum' => 'invalid_value'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function walletCreditsDefinition(): array
    {
        $feeTypes = FeeType::options();

        return [
            'type' => 'array',
            'x-error' => ['type' => 'invalid_type'],
            'items' => [
                'type' => 'object',
                'additionalProperties' => ['not' => true, 'x-error' => 'unsupported_key'],
                'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                'required' => ['localId', 'type', 'payload'],
                'properties' => [
                    'localId' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                    ],
                    'type' => [
                        'type' => 'string',
                        'const' => 'wallet_credit',
                        'x-error' => ['type' => 'invalid_type', 'const' => 'invalid_value'],
                    ],
                    'payload' => [
                        'type' => 'object',
                        'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                        'properties' => [
                            'paidCredits' => [
                                'type' => ['string', 'null'],
                                'x-error' => ['type' => 'invalid_type'],
                            ],
                            'grantedCredits' => [
                                'type' => ['string', 'null'],
                                'x-error' => ['type' => 'invalid_type'],
                            ],
                            'rateAmount' => [
                                'type' => ['string', 'null'],
                                'x-error' => ['type' => 'invalid_type'],
                            ],
                            'currency' => [
                                'type' => ['string', 'null'],
                                'enum' => [...self::currencies(), null],
                                'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_currency'],
                            ],
                            'expirationAt' => [
                                'type' => ['string', 'null'],
                                'format' => 'date-time',
                                'x-error' => ['type' => 'invalid_type', 'format' => 'invalid_format'],
                            ],
                            'appliesTo' => [
                                'type' => ['object', 'null'],
                                'x-error' => ['type' => 'invalid_type'],
                                'properties' => [
                                    'feeTypes' => [
                                        'type' => ['array', 'null'],
                                        'x-error' => ['type' => 'invalid_type'],
                                        'items' => [
                                            'type' => 'string',
                                            'enum' => $feeTypes,
                                            'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                                        ],
                                    ],
                                    'billableMetricCodes' => [
                                        'type' => ['array', 'null'],
                                        'x-error' => ['type' => 'invalid_type'],
                                        'items' => [
                                            'type' => 'string',
                                            'minLength' => 1,
                                            'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                                        ],
                                    ],
                                ],
                            ],
                            'recurringTransactionRules' => [
                                'type' => ['array', 'null'],
                                'x-error' => ['type' => 'invalid_type'],
                                // NOTE: rules live inside the free-form payload, so
                                // unknown keys are accepted here too.
                                'items' => [
                                    'type' => 'object',
                                    'x-error' => ['type' => 'invalid_type', 'required' => 'value_is_mandatory'],
                                    'properties' => [
                                        'trigger' => [
                                            'type' => ['string', 'null'],
                                            'enum' => [...self::RULE_TRIGGERS, null],
                                            'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                                        ],
                                        'interval' => [
                                            'type' => ['string', 'null'],
                                            'enum' => [...self::RULE_INTERVALS, null],
                                            'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                                        ],
                                        'method' => [
                                            'type' => ['string', 'null'],
                                            'enum' => [...self::RULE_METHODS, null],
                                            'x-error' => ['type' => 'invalid_type', 'enum' => 'invalid_value'],
                                        ],
                                        'thresholdCredits' => [
                                            'type' => ['string', 'null'],
                                            'x-error' => ['type' => 'invalid_type'],
                                        ],
                                        'targetOngoingBalance' => [
                                            'type' => ['string', 'null'],
                                            'x-error' => ['type' => 'invalid_type'],
                                        ],
                                        'grantsTargetTopUp' => [
                                            'type' => ['boolean', 'null'],
                                            'x-error' => ['type' => 'invalid_type'],
                                        ],
                                        'paidCredits' => [
                                            'type' => ['string', 'null'],
                                            'x-error' => ['type' => 'invalid_type'],
                                        ],
                                        'grantedCredits' => [
                                            'type' => ['string', 'null'],
                                            'x-error' => ['type' => 'invalid_type'],
                                        ],
                                        'startedAt' => [
                                            'type' => ['string', 'null'],
                                            'format' => 'date-time',
                                            'x-error' => ['type' => 'invalid_type', 'format' => 'invalid_format'],
                                        ],
                                        'expirationAt' => [
                                            'type' => ['string', 'null'],
                                            'format' => 'date-time',
                                            'x-error' => ['type' => 'invalid_type', 'format' => 'invalid_format'],
                                        ],
                                        'transactionName' => [
                                            'type' => ['string', 'null'],
                                            'minLength' => 1,
                                            'x-error' => ['type' => 'invalid_type', 'minLength' => 'invalid_value'],
                                        ],
                                        'invoiceRequiresSuccessfulPayment' => [
                                            'type' => ['boolean', 'null'],
                                            'x-error' => ['type' => 'invalid_type'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
