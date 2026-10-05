<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions\Validators;

use App\Models\QuoteVersion;
use App\Services\BaseResult;
use App\Services\Validators\Currencies;

/**
 * Port of Rails' QuoteVersions::Validators::BaseOrderTypeValidator
 * (app/services/quote_versions/validators/base_order_type_validator.rb).
 *
 * Runs the structural pass before the business one so DB lookups only ever
 * see a payload of the expected shape. Subclasses name the two passes for
 * their order type.
 *
 * The CurrencyValidation and BillingEntityValidation concerns
 * (currency_validation.rb / billing_entity_validation.rb) fold in here as
 * the shared `validateCurrency` / `validateBillingEntity` methods: the deal
 * currency must always be an ISO 4217 code when set and becomes mandatory
 * once the version is approved; the billing entity a deal is issued by is a
 * reference on the version, checked like the quoted plans and add-ons (a
 * blank value is legitimate — the deal then follows the customer's own
 * entity).
 */
abstract class BaseOrderTypeValidator
{
    /** @var array<string, list<string>> */
    protected array $errors = [];

    /** @var array<string, mixed>|null the normalized billing items */
    protected ?array $billingItems = null;

    public function __construct(
        protected BaseResult $result,
        protected QuoteVersion $quoteVersion,
        protected string $scope,
    ) {}

    /** The business pass — implemented per order type. */
    abstract protected function businessValid(): bool;

    /** The schema definition class for the order type. */
    abstract protected static function schemaClass(): string;

    public function valid(): bool
    {
        if (! $this->structuralValidator()->valid()) {
            return false;
        }

        return $this->businessValid();
    }

    /** The structural pass — the schema definitions for the order type. */
    protected function structuralValidator(): StructuralValidator
    {
        return new StructuralValidator(
            $this->result,
            $this->normalizedBillingItems(),
            $this->scope,
            (static::schemaClass())::definition($this->scope),
        );
    }

    /** Rails: `normalized_billing_items` — stringified keys, default {}. */
    protected function normalizedBillingItems(): array
    {
        if ($this->billingItems !== null) {
            return $this->billingItems;
        }

        $items = $this->quoteVersion->billing_items;

        if (! is_array($items)) {
            return $this->billingItems = [];
        }

        return $this->billingItems = $this->deepStringifyKeys($items);
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    protected function deepStringifyKeys(array $items): array
    {
        $stringified = [];

        foreach ($items as $key => $value) {
            $stringified[(string) $key] = is_array($value)
                ? $this->deepStringifyKeys($value)
                : $value;
        }

        return $stringified;
    }

    // -- Error accumulation (Rails' BaseValidator) ----------------------------

    protected function addError(string $field, string $errorCode): false
    {
        $this->errors[$field][] = $errorCode;

        return false;
    }

    protected function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    protected function reportErrors(): false
    {
        $this->result->validationFailure($this->errors);

        return false;
    }

    // -- CurrencyValidation ----------------------------------------------------

    /**
     * The deal currency is validated the same way whatever the order type:
     * mandatory once the version is approved, and always an ISO 4217 code
     * when set.
     */
    protected function validateCurrency(): void
    {
        $currency = $this->quoteVersion->currency;

        if ($currency === null || $currency === '') {
            if ($this->scope === 'approve') {
                $this->addError('currency', 'value_is_mandatory');
            }

            return;
        }

        if (! Currencies::valid($currency)) {
            $this->addError('currency', 'invalid_currency');
        }
    }

    // -- BillingEntityValidation ----------------------------------------------

    /**
     * The billing entity a deal is issued by is a reference on the version,
     * like the quoted plans and add-ons. organization.billing_entities is
     * scoped to the active, non-deleted ones, so an archived entity cannot be
     * quoted either.
     */
    protected function validateBillingEntity(): void
    {
        $billingEntityId = $this->quoteVersion->billing_entity_id;

        if ($billingEntityId === null || $billingEntityId === '') {
            return;
        }

        $exists = $this->quoteVersion->organization
            ->billingEntities()
            ->where('billing_entities.id', $billingEntityId)
            ->exists();

        if (! $exists) {
            $this->addError('billing_entity_id', 'billing_entity_not_found');
        }
    }
}
