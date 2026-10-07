<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Collection;
use App\Services\Failures\FailedResult;
use Illuminate\Database\Eloquent\Model;
use App\Models\AppliedInvoiceCustomSection;

/**
 * Port of Rails' Invoices::ApplyInvoiceCustomSectionsService
 * (app/services/invoices/apply_invoice_custom_sections_service.rb): snapshots
 * the applicable custom sections onto an invoice. The sections come either
 * from explicit ids, from the participating resources' selections
 * (subscription / wallet / wallet transaction), or fall back to the
 * customer's configurable selection (its own or the billing entity's) —
 * always unioned with the customer's system-generated sections.
 *
 * TODO(port): resource-type sections (product filters / contracts) arrive
 * with the filters pipeline — only subscription / wallet / wallet-transaction
 * resources carry `selectedInvoiceCustomSections` today.
 */
class ApplyInvoiceCustomSectionsService extends BaseService
{
    /**
     * @param  array<int, Model|mixed>  $resources
     * @param  list<string>  $customSectionIds
     */
    public function __construct(
        private readonly Invoice $invoice,
        private readonly array $resources = [],
        private readonly array $customSectionIds = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_sections');

        try {
            $result->applied_sections = collect();

            if ($this->skipCustomSections()) {
                return $result;
            }

            foreach ($this->applicableSections() as $customSection) {
                AppliedInvoiceCustomSection::query()->create([
                    'invoice_id' => $this->invoice->id,
                    'organization_id' => $this->invoice->organization_id,
                    'code' => $customSection->code,
                    'details' => $customSection->details,
                    'display_name' => $customSection->display_name,
                    'name' => $customSection->name,
                ]);
            }

            $result->applied_sections = $this->invoice->appliedInvoiceCustomSections;

            return $result;
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }
    }

    private function skipCustomSections(): bool
    {
        if ($this->participatingResources()->contains(fn (Model $resource): bool => $this->resourceHasInvoiceCustomSections($resource))) {
            return false;
        }

        if ($this->resources !== [] && $this->participatingResources()->isEmpty()) {
            return true;
        }

        if ($this->customSectionIds !== []) {
            return false;
        }

        return (bool) $this->invoice->customer->skip_invoice_custom_sections;
    }

    /**
     * @return Collection<int, \App\Models\InvoiceCustomSection>
     */
    private function applicableSections(): Collection
    {
        if ($this->customSectionIds !== []) {
            $manualSections = $this->invoice->organization->invoiceCustomSections()
                ->whereIn('id', $this->customSectionIds)
                ->get();
        } elseif ($this->resources !== []) {
            $manualSections = $this->sectionsFromResources();
        } else {
            $manualSections = $this->invoice->customer->configurableInvoiceCustomSections();
        }

        $systemGenerated = $this->invoice->customer->systemGeneratedInvoiceCustomSections()->get();

        // Rails: manual_sections | customer.system_generated_invoice_custom_sections
        return $manualSections->merge($systemGenerated)->unique('id')->values();
    }

    /**
     * @return Collection<int, \App\Models\InvoiceCustomSection>
     */
    private function sectionsFromResources(): Collection
    {
        $participating = $this->participatingResources();

        [$withSections, $withoutSections] = $participating->partition(
            fn (Model $resource): bool => $this->resourceHasInvoiceCustomSections($resource),
        );

        if ($withSections->isEmpty()) {
            return $this->invoice->customer->configurableInvoiceCustomSections();
        }

        $sections = $withSections
            ->flatMap(fn (Model $resource): Collection => $resource->selectedInvoiceCustomSections()->get())
            ->unique('id')
            ->values();

        if ($withoutSections->isNotEmpty()) {
            $sections = $sections
                ->merge($this->invoice->customer->configurableInvoiceCustomSections())
                ->unique('id')
                ->values();
        }

        return $sections;
    }

    /**
     * @return Collection<int, Model>
     */
    private function participatingResources(): Collection
    {
        return collect($this->resources)
            ->filter(fn ($resource): bool => $resource instanceof Model)
            ->reject(fn (Model $resource): bool => (bool) $resource->skip_invoice_custom_sections)
            ->values();
    }

    private function resourceHasInvoiceCustomSections(Model $resource): bool
    {
        if (! method_exists($resource, 'selectedInvoiceCustomSections')) {
            return false;
        }

        return $resource->selectedInvoiceCustomSections()->exists();
    }
}
