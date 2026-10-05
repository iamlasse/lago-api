<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes\Invoices;

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\Services\Integrations\Aggregator\Taxes\BaseService as TaxesBaseService;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::Invoices::BaseService
 * (app/services/integrations/aggregator/taxes/invoices/base_service.rb) —
 * the taxable-fee selection and (Anrok) charge grouping shared by the
 * finalized / draft invoice tax requests.
 */
abstract class BaseService extends TaxesBaseService
{
    /** @var list<Fee> */
    protected array $fees;

    private array $orderedFees;

    private array $feeGroups;

    public function __construct(
        protected readonly Invoice $invoice,
        ?array $fees = null,
    ) {
        $this->fees = $fees ?? $invoice->fees->all();

        parent::__construct(integration: $this->integration());
    }

    protected function customer(): ?Customer
    {
        return $this->invoice->customer;
    }

    /**
     * NOTE: A fee with no taxable base incurs no tax, so it is left out of
     * the request to keep the payload under the provider line-item limit
     * (Anrok and Avalara reject payloads above 1200 items). Excluded fees are
     * absent from the response and keep their zero taxes. The invoice itself
     * is still reported even when nothing on it is taxable, so one fee stands
     * in for it rather than sending an empty array, which both providers
     * reject.
     *
     * @return list<Fee>
     */
    protected function taxable_fees(): array
    {
        $taxable = array_values(array_filter(
            $this->ordered_fees(),
            fn (Fee $fee) => $fee->taxable(),
        ));

        if ($taxable === []) {
            return $this->ordered_fees() === [] ? [] : [$this->ordered_fees()[0]];
        }

        return $taxable;
    }

    /**
     * Persisted fees keep the same order across reporting attempts, including
     * ties in created_at. Unsaved preview and usage fees retain their
     * generation order.
     *
     * @return list<Fee>
     */
    protected function ordered_fees(): array
    {
        if (! isset($this->orderedFees)) {
            $fees = $this->fees;
            uasort($fees, function (Fee $a, Fee $b): int {
                $aCreated = $a->created_at?->getTimestamp() ?? 0;
                $bCreated = $b->created_at?->getTimestamp() ?? 0;

                return [$aCreated, (string) $a->id] <=> [$bCreated, (string) $b->id];
            });

            $this->orderedFees = array_values($fees);
        }

        return $this->orderedFees;
    }

    /**
     * NOTE: A charge split by charge filters or by grouped_by yields one fee
     * per combination, so a single charge could take dozens of the 1200 line
     * items both providers accept. Taxation is identical across the split,
     * and equally across the subscriptions and periods one charge may be
     * billed for on one invoice, so those collapse into the same line. Only
     * for Anrok, which rounds tax once per transaction: Avalara rounds per
     * line, so merging lines would change its total.
     *
     * @return list<Fee|ChargeFeeGroup>
     */
    protected function payload_fees(): array
    {
        if ($this->integration()->type === \App\Models\Integration::ANROK_TYPE) {
            return ChargeFeeGroup::build($this->taxable_fees());
        }

        return $this->taxable_fees();
    }

    /** @return array<string, ChargeFeeGroup> */
    protected function fee_groups(): array
    {
        if (! isset($this->feeGroups)) {
            $this->feeGroups = [];

            foreach ($this->payload_fees() as $payloadFee) {
                if ($payloadFee instanceof ChargeFeeGroup) {
                    $this->feeGroups[$payloadFee->itemKey()] = $payloadFee;
                }
            }
        }

        return $this->feeGroups;
    }

    /**
     * Rails: `split_group_taxes` — expand the grouped-charge tax lines back
     * over their member fees.
     *
     * @param  list<\App\Services\Integrations\Aggregator\Taxes\TaxResult>  $feeTaxes
     * @return list<\App\Services\Integrations\Aggregator\Taxes\TaxResult>
     */
    protected function split_group_taxes(array $feeTaxes): array
    {
        $feeGroups = $this->fee_groups();
        $split = [];

        foreach ($feeTaxes as $item) {
            $group = $feeGroups[$item->itemKey] ?? $feeGroups[$item->itemId] ?? null;

            $split[] = $group !== null ? $group->splitTaxes($item) : [$item];
        }

        return array_merge(...array_map('array_values', $split));
    }

    protected function process_response(array $body): void
    {
        parent::process_response($body);

        if ($this->result()->success() && is_array($this->result()->fees)) {
            $this->result()->fees = $this->split_group_taxes($this->result()->fees);
        }
    }

    /**
     * Rails: `no_taxable_fees_result` — only an invoice carrying no fee at
     * all reaches this, and it has nothing to report.
     */
    protected function no_taxable_fees_result(): \App\Services\BaseResult
    {
        $this->result()->fees = [];

        return $this->result();
    }
}
