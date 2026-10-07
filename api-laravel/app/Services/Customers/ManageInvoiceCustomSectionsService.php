<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Customers::ManageInvoiceCustomSectionsService
 * (app/services/customers/manage_invoice_custom_sections_service.rb): sets
 * the customer's skip flag and/or the exact manual-section selection
 * (by ids or codes), replacing the join rows.
 */
class ManageInvoiceCustomSectionsService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
        private readonly ?bool $skipInvoiceCustomSections,
        private readonly ?array $sectionIds = null,
        private readonly ?array $sectionCodes = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customer');

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        if ($this->sectionIds !== null && $this->sectionCodes !== null) {
            return $result->validationFailure([
                'invoice_custom_sections' => ['section_ids_and_section_codes_sent_together'],
            ]);
        }

        if ($this->skipInvoiceCustomSections !== null
            && ($this->sectionIds !== null || $this->sectionCodes !== null)) {
            return $result->validationFailure([
                'invoice_custom_sections' => ['skip_sections_and_selected_ids_sent_together'],
            ]);
        }

        try {
            DB::transaction(function (): void {
                $customer = $this->customer;

                if ($this->skipInvoiceCustomSections !== null) {
                    if ($this->skipInvoiceCustomSections) {
                        // Rails: customer.selected_invoice_custom_sections =
                        // InvoiceCustomSection.none — clearing the through
                        // collection removes every join row.
                        $customer->appliedInvoiceCustomSections()->delete();
                    }

                    $customer->skip_invoice_custom_sections = $this->skipInvoiceCustomSections;
                }

                if ($this->sectionIds !== null || $this->sectionCodes !== null) {
                    $customer->skip_invoice_custom_sections = false;

                    if (! $this->selectedSectionsMatch()) {
                        $this->assignSelectedSections();
                    }
                }

                $errors = $customer->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $customer->save();
            });

            $result->customer = $this->customer;

            return $result;
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }
    }

    private function selectedSectionsMatch(): bool
    {
        $selected = $this->customer->selectedInvoiceCustomSections()->get();

        if ($this->sectionIds !== null) {
            return $selected->pluck('id')->all() === $this->sectionIds;
        }

        if ($this->sectionCodes !== null) {
            return $selected->pluck('code')->all() === $this->sectionCodes;
        }

        return false;
    }

    private function assignSelectedSections(): void
    {
        $customer = $this->customer;

        // Note: when assigning billing entity's sections, an empty array will
        // be sent — an empty selection legitimately clears the manual rows.
        $selectedSections = $customer->organization->invoiceCustomSections()
            ->where(function ($query): void {
                if ($this->sectionIds !== null) {
                    $query->whereIn('id', $this->sectionIds);
                } else {
                    $query->whereIn('code', $this->sectionCodes ?? []);
                }
            })
            ->get();

        $systemGeneratedSections = $customer->systemGeneratedInvoiceCustomSections()->get();
        $systemGeneratedIds = $systemGeneratedSections->pluck('id')->all();

        // Clear existing manual sections (keep the system-generated ones).
        $manualRows = $customer->appliedInvoiceCustomSections()
            ->when(
                $systemGeneratedIds !== [],
                fn ($query) => $query->whereNotIn('invoice_custom_section_id', $systemGeneratedIds),
            )
            ->get();

        foreach ($manualRows as $row) {
            $row->delete();
        }

        // Create new join records for selected sections.
        foreach ($selectedSections as $section) {
            $customer->appliedInvoiceCustomSections()->create([
                'organization_id' => $customer->organization_id,
                'billing_entity_id' => $customer->billing_entity_id,
                'invoice_custom_section_id' => $section->id,
            ]);
        }
    }
}
