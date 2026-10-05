<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions;

use App\Models\QuoteVersion;
use App\Services\BaseResult;
use App\Models\BillingEntity;
use App\Services\BaseService;
use App\Support\Utils\Datetime;

/**
 * Port of Rails' QuoteVersions::ComputeMentionVariablesService
 * (app/services/quote_versions/compute_mention_variables_service.rb) — the
 * raw, locale-independent snapshot frozen on the version at approval time.
 * Locale formatting happens at read time (MentionVariablesLocalizer), which
 * is not part of this port yet.
 *
 * TODO(port): organization_logo (ActiveStorage attachments are not ported).
 */
class ComputeMentionVariablesService extends BaseService
{
    public function __construct(
        private readonly QuoteVersion $quoteVersion,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('mention_variables');

        $quote = $this->quoteVersion->quote;
        $customer = $quote->customer;
        $organization = $quote->organization;
        $billingEntity = $this->quoteVersion->resolvedBillingEntity();

        $result->mention_variables = [
            'customer_name' => $this->customerDisplayName($customer),
            'customer_email' => $customer->email,
            'organization_name' => $organization->name,
            'organization_logo' => null, // TODO(port): organization.logo_url (ActiveStorage).
            'billing_entity_name' => $billingEntity?->name,
            'billing_entity_legal_name' => $billingEntity?->legal_name,
            'billing_entity_address' => $this->billingEntityAddress($billingEntity),
            'billing_entity_tax_id' => $billingEntity?->tax_identification_number,
            'billing_entity_email' => $billingEntity?->email,
            'quote_number' => $quote->number,
            'quote_date' => $quote->created_at
                ->copy()->setTimezone($customer->applicableTimezone())
                ->toDateString(),
            'quote_version' => (string) $this->quoteVersion->version(),
            'quote_currency' => $this->quoteVersion->currency,
            'commercial_terms_term_duration' => $this->termDuration(),
            'commercial_terms_start_date' => $this->termStartDate(),
            'commercial_terms_payment_terms' => $customer->applicableNetPaymentTerm(),
        ];

        return $result;
    }

    // -- Helpers ------------------------------------------------------------------

    /** Rails: Customer#display_name. */
    protected function customerDisplayName(mixed $customer): ?string
    {
        $names = [];

        $legalName = ($customer->legal_name ?? '') !== '' ? $customer->legal_name : null;
        $name = ($customer->name ?? '') !== '' ? $customer->name : null;

        if ($legalName !== null || $name !== null) {
            $names[] = $legalName ?? $name;
        }

        if (($customer->firstname ?? '') !== '' || ($customer->lastname ?? '') !== '') {
            if ($names !== []) {
                $names[] = '-';
            }

            if (($customer->firstname ?? '') !== '') {
                $names[] = $customer->firstname;
            }

            if (($customer->lastname ?? '') !== '') {
                $names[] = $customer->lastname;
            }
        }

        $joined = implode(' ', $names);

        return $joined !== '' ? $joined : null;
    }

    /**
     * Structured, locale-independent address parts. Formatting happens at
     * read time (Rails: MentionVariablesLocalizer).
     *
     * @return array<string, string>|null
     */
    protected function billingEntityAddress(?BillingEntity $billingEntity): ?array
    {
        if ($billingEntity === null) {
            return null;
        }

        return [
            'address_line1' => $billingEntity->address_line1,
            'address_line2' => $billingEntity->address_line2,
            'locality' => $billingEntity->city,
            'postal_code' => $billingEntity->zipcode,
            'administrative_area' => $billingEntity->state,
            'country_code' => $billingEntity->country,
        ];
    }

    /**
     * Picks the largest whole unit between the two dates (years, then months,
     * then days) and returns a raw { unit, count } pair. A 12-month span
     * becomes 1 year.
     *
     * @return array{unit: string, count: int}|null
     */
    protected function termDuration(): ?array
    {
        $startDate = $this->termStartDate();
        $endDate = $this->termEndDate();

        if ($startDate === null || $endDate === null) {
            return null;
        }

        $start = Datetime::parseIso8601($startDate);
        $end = Datetime::parseIso8601($endDate);

        if ($start === null || $end === null) {
            return null;
        }

        $months = $this->wholeMonthsBetween($start, $end);

        if ($months < 1) {
            return ['unit' => 'days', 'count' => $start->diffInDays($end)];
        }

        if ($months % 12 === 0) {
            return ['unit' => 'years', 'count' => intdiv($months, 12)];
        }

        return ['unit' => 'months', 'count' => $months];
    }

    /**
     * The deal term is not a quote-level field: every billing item carries
     * its own dates, so the commercial term is the span the quoted items
     * cover. Plans state it as startDate/endDate, and one_off add-ons as the
     * service period their fee is billed for.
     */
    protected function termStartDate(): ?string
    {
        if ($this->isOneOff()) {
            return $this->minDate($this->addOnDates('fromDatetime'));
        }

        return $this->minDate($this->planDates('startDate')) ?? $this->amendedSubscriptionDate();
    }

    protected function termEndDate(): ?string
    {
        if ($this->isOneOff()) {
            return $this->maxDate($this->addOnDates('toDatetime'));
        }

        return $this->maxDate($this->planDates('endDate'));
    }

    protected function isOneOff(): bool
    {
        return $this->quoteVersion->quote->order_type === 'one_off';
    }

    /**
     * An amendment restates a subscription that is already running and its
     * plan need not carry a start date, since the replacement inherits the
     * target's anniversary date.
     */
    protected function amendedSubscriptionDate(): ?string
    {
        $quote = $this->quoteVersion->quote;

        if ($quote->order_type !== 'subscription_amendment') {
            return null;
        }

        $subscriptionAt = $quote->subscription?->subscription_at;

        if ($subscriptionAt === null) {
            return null;
        }

        $customer = $quote->customer;

        return $subscriptionAt
            ->copy()
            ->setTimezone($customer->applicableTimezone())
            ->toDateString();
    }

    /**
     * Same parsing as Orders::SubscriptionCreation::ExecuteService, the
     * service these dates feed.
     *
     * @return list<string>
     */
    protected function planDates(string $key): array
    {
        $dates = [];

        foreach (DealExpiration::billingItems($this->quoteVersion)['plans'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $date = Datetime::parseIso8601($item['payload'][$key] ?? null)?->toDateString();

            if ($date !== null) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Overrides win over the payload, the resolution
     * Orders::OneOff::ExecuteService applies to the dates it bills.
     *
     * @return list<string>
     */
    protected function addOnDates(string $key): array
    {
        $dates = [];

        foreach (DealExpiration::billingItems($this->quoteVersion)['addOns'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = $item['overrides'][$key] ?? $item['payload'][$key] ?? null;
            $date = Datetime::parseIso8601($value)?->toDateString();

            if ($date !== null) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Whole calendar months between two dates, rounding down a partial
     * trailing month.
     */
    protected function wholeMonthsBetween(mixed $from, mixed $to): int
    {
        $months = ($to->year * 12 + $to->month) - ($from->year * 12 + $from->month);

        if ($to->day < $from->day) {
            $months -= 1;
        }

        return $months;
    }

    /**
     * @param  list<string>  $dates
     */
    protected function minDate(array $dates): ?string
    {
        return $dates === [] ? null : min($dates);
    }

    /**
     * @param  list<string>  $dates
     */
    protected function maxDate(array $dates): ?string
    {
        return $dates === [] ? null : max($dates);
    }
}
