<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Validators\EuVatRates;

/**
 * Port of Rails' Customers::EuAutoTaxesService — computes the automatic EU
 * tax code (lago_eu_*) for a customer, when the billing entity manages EU
 * taxes.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): PendingViesCheck persistence + Customers::ViesCheckJob —
 *   when the customer has a tax_identification_number, Rails schedules an
 *   async VIES check and returns `vies_check_pending`; here the failure is
 *   returned without persisting the pending check.
 * - TODO(port): Valvat::Syntax validation for the FR B2B-only territory
 *   check — any non-blank tax_identification_number counts as B2B.
 */
class EuAutoTaxesService extends BaseService
{
    /** Rails: B2B_ONLY_TERRITORY_COUNTRIES. */
    public const B2B_ONLY_TERRITORY_COUNTRIES = ['FR'];

    public function __construct(
        private readonly Customer $customer,
        private readonly bool $newRecord,
        private readonly bool $taxAttributesChanged,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('tax_code');

        if (! $this->shouldApplyEuTaxes()) {
            return $result->notAllowedFailure('eu_tax_not_applicable');
        }

        $territoryTaxCode = $this->detectSpecialTerritory();

        if ($territoryTaxCode !== null) {
            $result->tax_code = $territoryTaxCode;

            // Rails: delete_pending_vies_check_if_exists — no model yet.

            return $result;
        }

        if ($this->customer->tax_identification_number !== null && $this->customer->tax_identification_number !== '') {
            // TODO(port): schedule the async VIES check (PendingViesCheck +
            // Customers::ViesCheckJob) before returning the pending failure.
            return $result->serviceFailure(
                'vies_check_pending',
                'VIES check scheduled asynchronously',
            );
        }

        $result->tax_code = $this->processNotViesTax();

        return $result;
    }

    /**
     * Rails: `detect_special_territory` — EU exception territories (Canary
     * Islands, Campione d'Italia, …) matched by postcode regex.
     */
    protected function detectSpecialTerritory(): ?string
    {
        $countryCode = $this->customer->country !== null ? mb_strtoupper($this->customer->country) : null;

        if ($countryCode === null || $countryCode === '' || $this->customer->zipcode === null || $this->customer->zipcode === '') {
            return null;
        }

        if (! in_array($countryCode, EuVatRates::countryCodes(), true)) {
            return null;
        }

        $countryRates = EuVatRates::countryRates($countryCode);

        if ($countryRates === null || $countryRates['exceptions'] === []) {
            return null;
        }

        $normalizedZip = preg_replace('/\s/', '', $this->customer->zipcode);

        foreach ($countryRates['exceptions'] as $exception) {
            if (preg_match('/'.$exception['postcode'].'/', (string) $normalizedZip) === 1) {
                return $this->territoryTaxCode($countryCode, $exception);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $exception */
    protected function territoryTaxCode(string $countryCode, array $exception): ?string
    {
        if (in_array($countryCode, self::B2B_ONLY_TERRITORY_COUNTRIES, true) && ! $this->isB2b()) {
            return null;
        }

        $exceptionCode = $this->parameterize($exception['name']);

        return 'lago_eu_'.mb_strtolower($countryCode).'_exception_'.$exceptionCode;
    }

    /**
     * Rails: `is_b2b?` — a tax identification number that passes
     * Valvat::Syntax. TODO(port): the Valvat syntax check — any non-blank
     * number currently counts as valid.
     */
    protected function isB2b(): bool
    {
        return $this->customer->tax_identification_number !== null
            && $this->customer->tax_identification_number !== '';
    }

    protected function shouldApplyEuTaxes(): bool
    {
        if (! $this->customer->billingEntity->eu_tax_management) {
            return false;
        }

        if ($this->newRecord) {
            return true;
        }

        $existingEuTaxes = $this->customer->taxes()
            ->where('code', 'ilike', 'lago_eu%')
            ->exists();

        return ! $existingEuTaxes || $this->taxAttributesChanged;
    }

    /**
     * Port of EuTaxCodeResolver#process_not_vies_tax.
     */
    protected function processNotViesTax(): string
    {
        $billingCountryCode = mb_strtoupper($this->customer->billingEntity->country ?? '');

        if ($this->customer->country === null || $this->customer->country === '') {
            return 'lago_eu_'.mb_strtolower($billingCountryCode).'_standard';
        }

        if (in_array(mb_strtoupper($this->customer->country), EuVatRates::countryCodes(), true)) {
            return 'lago_eu_'.mb_strtolower($this->customer->country).'_standard';
        }

        return 'lago_eu_tax_exempt';
    }

    /**
     * Rails: `String#parameterize.underscore` ("Canary Islands" ->
     * "canary_islands").
     */
    protected function parameterize(string $value): string
    {
        return \Illuminate\Support\Str::slug($value, '_', 'en');
    }
}
