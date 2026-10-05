<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\OutOfMemoryError;
use App\Services\Integrations\Aggregator\ServerContentionError;
use App\Services\Integrations\Aggregator\BaseService as AggregatorBaseService;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::BaseService
 * (app/services/integrations/aggregator/taxes/base_service.rb) — the shared
 * response processing of the Nango tax endpoints: fee/tax_breakdown mapping
 * into TaxResults, the special taxation types, and the failure handling
 * (error details + tax error webhook).
 */
abstract class BaseService extends AggregatorBaseService
{
    /** Rails: SPECIAL_TAXATION_TYPES. */
    public const array SPECIAL_TAXATION_TYPES = [
        'exempt', 'notCollecting', 'productNotTaxed', 'jurisNotTaxed', 'jurisHasNoTax',
    ];

    public const string CUSTOMER_ADDRESS_INVALID = 'customerAddressCouldNotResolve';

    public const string OUT_OF_MEMORY_ERROR = 'function_runtime_out_of_memory';

    /** The per-service result the processing writes into (set by the subclass). */
    protected BaseResult $result;

    private ?IntegrationCustomer $integrationCustomer = null;

    /** Rails: `delegate :customer, to: :invoice` on the invoice subclass. */
    abstract protected function customer(): ?Customer;

    /** The per-service result the processing writes into (set by the subclass). */
    protected function result(): BaseResult
    {
        return $this->result;
    }

    /**
     * Rails: `integration_customer` — the customer's tax-kind integration
     * customer (anrok or avalara); nil when the customer has none, and the
     * whole request is then skipped by the create services.
     */
    protected function integration_customer(): ?IntegrationCustomer
    {
        if ($this->integrationCustomer === null) {
            $customer = $this->customer();

            $this->integrationCustomer = $customer
                ?->integrationCustomers
                ->first(fn (IntegrationCustomer $ic) => $ic->taxKind());
        }

        return $this->integrationCustomer;
    }

    /** Rails: `integration` — the tax integration behind the customer connection. */
    protected function integration(): ?Integration
    {
        return $this->integration_customer()?->integration;
    }

    protected function headers(): array
    {
        return [
            'Connection-Id' => $this->integration()?->getFromSecrets('connection_id'),
            'Authorization' => 'Bearer '.$this->secret_key(),
            'Provider-Config-Key' => $this->providerKey(),
        ];
    }

    /** Rails: `assign_external_customer_id` — first sync stamps the Lago external id. */
    protected function assign_external_customer_id(): void
    {
        if (! $this->result()->success()) {
            return;
        }

        $integrationCustomer = $this->integration_customer();

        if ($integrationCustomer === null || $integrationCustomer->external_customer_id !== null) {
            return;
        }

        $integrationCustomer->external_customer_id = $this->customer()->external_id;
        $integrationCustomer->save();
    }

    /**
     * Rails: `process_response(body)` — the succeeded invoice's fees become
     * TaxResults; a failed invoice is a service failure (with the special
     * out-of-memory / server-contention errors raised for the job retries).
     *
     * @param  array<string, mixed>  $body
     */
    protected function process_response(array $body): void
    {
        $fees = $body['succeededInvoices'][0]['fees'] ?? null;

        if (is_array($fees)) {
            $taxResults = [];

            foreach ($fees as $fee) {
                $taxesToPay = $fee['tax_amount_cents'];

                $taxResults[] = new TaxResult(
                    itemKey: $fee['item_key'] ?? null,
                    itemId: $fee['item_id'] ?? null,
                    itemCode: $fee['item_code'] ?? null,
                    amountCents: $fee['amount_cents'] ?? null,
                    taxAmountCents: $taxesToPay,
                    taxBreakdown: $this->tax_breakdown($fee['tax_breakdown'] ?? [], $taxesToPay),
                );
            }

            $this->result()->fees = $taxResults;
            $this->result()->succeeded_id = $body['succeededInvoices'][0]['id'];

            return;
        }

        [$code, $message] = $this->retrieve_error_details($body['failedInvoices'][0]['validation_errors'] ?? null);

        if (str_contains($message, self::OUT_OF_MEMORY_ERROR)) {
            throw new OutOfMemoryError();
        }

        if ($this->server_contention_error($message)) {
            throw new ServerContentionError($message);
        }

        // Do not send this webhook in preview mode (the customer is not persisted).
        if ($this->customer() !== null && $this->customer()->exists) {
            $this->deliver_tax_error_webhook($this->customer(), $code, $message);
        }

        $this->result()->serviceFailure(code: $code, message: $message);
    }

    /**
     * Rails: `tax_breakdown` — map the provider jurisdictions: special
     * taxation types (and entries without a rate) carry zero taxes and a
     * humanized name; exact taxes booked at zero because the seller pays
     * them collapse to the generic "Tax" row.
     *
     * @param  list<array<string, mixed>>  $breakdown
     * @return list<TaxBreakdownItem>
     */
    protected function tax_breakdown(array $breakdown, int|float $taxesToPay): array
    {
        return array_map(function (array $b) use ($taxesToPay): TaxBreakdownItem {
            if (in_array($b['type'] ?? null, self::SPECIAL_TAXATION_TYPES, true)) {
                return new TaxBreakdownItem(
                    name: $this->humanize_tax_name(($b['reason'] ?? null) ?: ($b['type'] ?? null)),
                    rate: '0.00',
                    taxAmount: 0,
                    type: $b['type'] ?? null,
                );
            }

            // Rails `elsif b["rate"]` — any non-nil, non-blank rate.
            if (isset($b['rate']) && $b['rate'] !== '' && $b['rate'] !== false) {
                // If exact taxes are at least one cent but booked taxes are zero, the seller pays them.
                if ($taxesToPay === 0 && $b['tax_amount'] >= 1) {
                    return new TaxBreakdownItem(name: 'Tax', rate: '0.00', taxAmount: 0, type: 'tax');
                }

                return new TaxBreakdownItem(
                    name: $b['name'] ?? null,
                    rate: $b['rate'],
                    taxAmount: $b['tax_amount'],
                    type: $b['type'] ?? null,
                );
            }

            return new TaxBreakdownItem(
                name: $this->humanize_tax_name(($b['reason'] ?? null) ?: ($b['type'] ?? null) ?: 'unknown_taxation'),
                rate: '0.00',
                taxAmount: 0,
                type: ($b['type'] ?? null) ?: 'unknown_taxation',
            );
        }, $breakdown);
    }

    protected function server_contention_error(string $message): bool
    {
        return str_contains($message, 'API limit') || str_contains($message, 'resource contention');
    }

    /**
     * Rails: `retrieve_error_details` — a hash validation error is its type
     * with the generic "Service failure" message; anything else (a string)
     * is the "validationError" code with the message verbatim.
     *
     * @return array{0: string, 1: string}
     */
    protected function retrieve_error_details(mixed $validationError): array
    {
        if (is_array($validationError)) {
            return [(string) ($validationError['type'] ?? 'validationError'), 'Service failure'];
        }

        return ['validationError', (string) $validationError];
    }

    /** Rails: `humanize_tax_name` — camelCase → words, first letter upcased. */
    protected function humanize_tax_name(string $camelizedName): string
    {
        $underscored = (string) preg_replace('/(?<!^)[A-Z]/', '_$0', $camelizedName);

        return ucfirst(mb_strtolower(str_replace('_', ' ', mb_trim($underscored, '_'))));
    }
}
