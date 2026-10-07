<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\InvoiceCustomSectionType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `invoice_custom_sections` — Rails' InvoiceCustomSection
 * (app/models/invoice_custom_section.rb): a reusable invoice text block
 * (header `display_name` + body `details`) that customers/billing entities
 * select and finalized invoices snapshot into applied sections.
 *
 * Rails uses Discard (deleted_at) — the same column drives Eloquent's
 * SoftDeletes here. Rails' `default_scope { kept }` is mirrored by the
 * SoftDeletes global scope.
 */
#[Fillable([
    'organization_id',
    'name',
    'code',
    'description',
    'display_name',
    'details',
    'section_type',
])]
#[Table(name: 'invoice_custom_sections')]
class InvoiceCustomSection extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: SECTION_TYPES. */
    public const SECTION_TYPES = ['manual' => 'manual', 'system_generated' => 'system_generated'];

    /**
     * Rails' ActiveRecord carries the schema's column defaults in every new
     * instance; Eloquent does not, so the NOT NULL DEFAULT columns are
     * declared here to keep reads identical to Rails.
     */
    protected $attributes = [
        'section_type' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'section_type' => InvoiceCustomSectionType::class,
            'deleted_at' => 'datetime',
        ];
    }

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :organization`. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `has_many :customer_applied_invoice_custom_sections, dependent: :destroy` (customers_invoice_custom_sections). */
    public function customerAppliedInvoiceCustomSections(): HasMany
    {
        return $this->hasMany(CustomerAppliedInvoiceCustomSection::class, 'invoice_custom_section_id');
    }

    /** Rails: `has_many :billing_entity_applied_invoice_custom_sections, dependent: :destroy` (billing_entities_invoice_custom_sections). */
    public function billingEntityAppliedInvoiceCustomSections(): HasMany
    {
        return $this->hasMany(BillingEntityAppliedInvoiceCustomSection::class, 'invoice_custom_section_id');
    }

    // -- Validations (port of the Rails model validations) --------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid. Rails:
     *   validates :name, presence: true
     *   validates :code, presence: true, uniqueness:
     *     {conditions: -> { where(deleted_at: nil) }, scope: :organization_id}
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];
        } else {
            $uniqueness = static::query()
                ->where('code', $this->code)
                ->where('organization_id', $this->organization_id)
                ->whereNull('deleted_at');

            if ($this->exists) {
                $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
            }

            if ($uniqueness->exists()) {
                $errors['code'] = ['value_already_exist'];
            }
        }

        return $errors;
    }

    // -- Scopes ---------------------------------------------------------------

    /**
     * Rails: `Organization#manual_invoice_custom_sections`
     * (`has_many :invoice_custom_sections, -> { where(section_type: "manual") }`) —
     * the only sections the admin UI lists / creates.
     *
     * @return Collection<int, self>
     */
    public static function manualFor(Organization $organization): Collection
    {
        return $organization->invoiceCustomSections()
            ->where('section_type', 'manual')
            ->orderBy('name')
            ->get();
    }
}
