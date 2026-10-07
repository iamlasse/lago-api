<?php

declare(strict_types=1);

namespace App\Services\InvoiceCustomSections;

use App\Models\InvoiceCustomSection;
use App\Services\BaseResult;
use Illuminate\Support\Collection;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' InvoiceCustomSections::AttachToResourceService
 * (app/services/invoice_custom_sections/attach_to_resource_service.rb):
 * syncs the `invoice_custom_section` param block
 * ({skip_invoice_custom_sections, invoice_custom_section_ids|codes}) onto a
 * section-carrying resource (subscription / wallet / wallet transaction),
 * creating and removing the join rows so the selection matches exactly.
 */
class AttachToResourceService extends BaseService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly Model $resource,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    private ?Collection $memoInvoiceCustomSections = null;

    /** @var list<string>|null */
    private ?array $memoSectionIdentifiers = null;

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        if (! array_key_exists('invoice_custom_section', $this->params)) {
            return $result;
        }

        try {
            DB::transaction(function (): void {
                if ($this->skipFlag() === null) {
                    $this->handleImplicitSkipFlag();
                } else {
                    $this->handleExplicitSkipFlag();
                }
            });

            return $result;
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }
    }

    private function skipFlag(): ?bool
    {
        $value = $this->params['invoice_custom_section']['skip_invoice_custom_sections'] ?? null;

        return $value === null ? null : (bool) $value;
    }

    private function sectionsParam(): mixed
    {
        $key = $this->apiContext()
            ? 'invoice_custom_section_codes'
            : 'invoice_custom_section_ids';

        return $this->params['invoice_custom_section'][$key] ?? null;
    }

    private function handleExplicitSkipFlag(): void
    {
        if ($this->skipFlag() === true) {
            $this->resource->update(['skip_invoice_custom_sections' => true]);
            $this->resource->appliedInvoiceCustomSections()->delete();

            return;
        }

        $this->resource->update(['skip_invoice_custom_sections' => false]);

        if ($this->sectionsParam() !== null) {
            $this->attachSections();
        }
    }

    private function handleImplicitSkipFlag(): void
    {
        if ($this->resource->skip_invoice_custom_sections) {
            return;
        }

        if ($this->sectionsParam() !== null) {
            $this->attachSections();
        }
    }

    private function attachSections(): void
    {
        $existingSectionIds = $this->resource->appliedInvoiceCustomSections()
            ->pluck('invoice_custom_section_id')
            ->all();
        $newSectionIds = $this->invoiceCustomSections()->pluck('id')->all();

        foreach ($this->invoiceCustomSections() as $section) {
            if (in_array($section->id, $existingSectionIds, true)) {
                continue;
            }

            $this->resource->appliedInvoiceCustomSections()->create([
                'invoice_custom_section_id' => $section->id,
                'organization_id' => $this->resource->organization_id,
            ]);
        }

        $this->removeObsoleteSections($existingSectionIds, $newSectionIds);
    }

    /**
     * @param  list<string>  $existingIds
     * @param  list<string>  $newIds
     */
    private function removeObsoleteSections(array $existingIds, array $newIds): void
    {
        $obsoleteIds = array_values(array_diff($existingIds, $newIds));

        if ($obsoleteIds !== []) {
            $this->resource->appliedInvoiceCustomSections()
                ->whereIn('invoice_custom_section_id', $obsoleteIds)
                ->delete();
        }
    }

    /**
     * @return Collection<int, InvoiceCustomSection>
     */
    private function invoiceCustomSections(): Collection
    {
        if (isset($this->memoInvoiceCustomSections)) {
            return $this->memoInvoiceCustomSections;
        }

        if ($this->sectionIdentifiers() === []) {
            return $this->memoInvoiceCustomSections = collect();
        }

        $identifier = $this->apiContext() ? 'code' : 'id';

        return $this->memoInvoiceCustomSections = $this->resource->organization
            ->invoiceCustomSections()
            ->whereIn($identifier, $this->sectionIdentifiers())
            ->get();
    }

    /**
     * @return list<string>
     */
    private function sectionIdentifiers(): array
    {
        if (isset($this->memoSectionIdentifiers)) {
            return $this->memoSectionIdentifiers;
        }

        $sectionsParam = $this->sectionsParam();

        if ($sectionsParam === null || $sectionsParam === []) {
            return $this->sectionIdentifiersMemo = [];
        }

        $identifiers = array_values(array_unique(array_map(
            strval(...),
            array_filter((array) $sectionsParam, fn ($value) => $value !== null),
        )));

        return $this->memoSectionIdentifiers = $identifiers;
    }
}
