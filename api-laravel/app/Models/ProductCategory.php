<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `product_categories`. Port of the Rails
 * ProductCategory model (app/models/product_category.rb).
 */
#[Fillable([
    'organization_id',
    'code',
    'name',
    'description',
    'invoice_display_name',
])]
#[Table(name: 'product_categories')]
class ProductCategory extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: CODE_FORMAT (CatalogCodeFormat concern). */
    public const CODE_FORMAT = '/\A(?!\.+\z)[a-zA-Z0-9_\-.]+\z/';

    // -- Relationships --------------------------------------------------------

    /** Rails: `has_many :products`. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // -- Domain methods ---------------------------------------------------------

    /** Rails: `invoice_name`. */
    public function invoiceName(): string
    {
        return ($this->invoice_display_name ?: $this->name) ?? '';
    }

    /** Rails: CatalogAttachable via the products' rate cards. */
    public function attachedToPlanOrSubscription(): bool
    {
        return RateCard::query()
            ->whereIn('product_id', $this->products()->select('products.id'))
            ->where(function ($query): void {
                $query->whereRaw('EXISTS (SELECT 1 FROM plan_rate_cards WHERE plan_rate_cards.rate_card_id = rate_cards.id AND plan_rate_cards.deleted_at IS NULL)')
                    ->orWhereRaw('EXISTS (SELECT 1 FROM contract_rate_cards WHERE contract_rate_cards.rate_card_id = rate_cards.id AND contract_rate_cards.deleted_at IS NULL)');
            })
            ->exists();
    }

    // -- Validations ------------------------------------------------------------

    /**
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        $this->validateCode($errors);

        return $errors;
    }

    /** @param array<string, list<string>> $errors */
    protected function validateCode(array &$errors): void
    {
        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];

            return;
        }

        if (preg_match(self::CODE_FORMAT, (string) $this->code) !== 1) {
            $errors['code'] = ['value_is_invalid'];

            return;
        }

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
}
