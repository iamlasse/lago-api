<?php

declare(strict_types=1);

namespace App\Models\IntegrationCollectionMappings;

use App\Services\Validators\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Database\Factories\NetsuiteCollectionMappingFactory;

/**
 * Port of Rails' IntegrationCollectionMappings::NetsuiteCollectionMapping
 * (app/models/integration_collection_mappings/netsuite_collection_mapping.rb)
 * — settings_accessors :tax_nexus, :tax_type, :tax_code, :currencies plus
 * the currency-mapping format and organization-level-only validations.
 */
#[UseFactory(NetsuiteCollectionMappingFactory::class)]
class NetsuiteCollectionMapping extends BaseCollectionMapping
{
    use HasFactory;
    // Settings-backed accessors stay classic get/set mutators: an
    // Attribute::make(set:) closure that only mutates $this->attributes as a
    // side effect gets clobbered — Eloquent snapshots the attributes before
    // invoking the setter and overwrites them with array_merge(snapshot,
    // return), so returning [] discards the pushToSettings write.

    public function getTaxNexusAttribute(): mixed
    {
        return $this->getFromSettings('tax_nexus');
    }

    public function setTaxNexusAttribute(mixed $value): void
    {
        $this->pushToSettings('tax_nexus', $value);
    }

    public function getTaxTypeAttribute(): mixed
    {
        return $this->getFromSettings('tax_type');
    }

    public function setTaxTypeAttribute(mixed $value): void
    {
        $this->pushToSettings('tax_type', $value);
    }

    public function getTaxCodeAttribute(): mixed
    {
        return $this->getFromSettings('tax_code');
    }

    public function setTaxCodeAttribute(mixed $value): void
    {
        $this->pushToSettings('tax_code', $value);
    }

    /** @return array<string, string>|null */
    public function getCurrenciesAttribute(): ?array
    {
        return $this->getFromSettings('currencies');
    }

    public function setCurrenciesAttribute(?array $value): void
    {
        $this->pushToSettings('currencies', $value);
    }

    // -- Validations ----------------------------------------------------------

    public function validateAttributes(): array
    {
        $errors = parent::validateAttributes();

        $currencies = $this->currencies;

        // validate :currency_mapping_format.
        if (! $this->isCurrencies()) {
            if ($currencies !== null) {
                // "Other mapping_types shouldn't have currencies, but if they
                // do, we validate the format" — Rails first flags the value.
                $errors['currencies'] = ['value_must_be_blank'];
            }
        } elseif ($currencies === null) {
            $errors['currencies'] = ['value_is_mandatory'];
        } elseif (! is_array($currencies)) {
            $errors['currencies'] = ['invalid_format'];
        } elseif ($currencies === []) {
            $errors['currencies'] = ['cannot_be_empty'];
        } elseif (! $this->currenciesHashValid($currencies)) {
            $errors['currencies'] = ['invalid_format'];
        }

        // validate :organization_level_only_mapping (Rails errors accumulate,
        // so this joins the org-mismatch code when both fire).
        if ($this->isCurrencies() && $this->billing_entity_id !== null) {
            $errors['billing_entity'] = [...($errors['billing_entity'] ?? []), 'value_must_be_blank'];
        }

        return $errors;
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::NETSUITE_TYPE);
        });

        // Rails STI: the stored type column is set on create.
        static::creating(function (self $mapping): void {
            $mapping->type = self::NETSUITE_TYPE;
        });
    }

    /**
     * Rails: `currencies_hash_valid?` — string keys from
     * Currencies::ACCEPTED_CURRENCIES, non-blank string values.
     *
     * @param  array<string, mixed>  $currencies
     */
    private function currenciesHashValid(array $currencies): bool
    {
        $accepted = Currencies::list();

        foreach ($currencies as $code => $externalCode) {
            if (! is_string($code) || ! in_array($code, $accepted, true)) {
                return false;
            }

            if (! is_string($externalCode) || $externalCode === '') {
                return false;
            }
        }

        return true;
    }
}
