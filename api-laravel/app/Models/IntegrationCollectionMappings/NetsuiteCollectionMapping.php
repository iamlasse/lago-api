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
    protected function taxNexus(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(get: function () {
            return $this->getFromSettings('tax_nexus');
        }, set: function (mixed $value) {
            $this->pushToSettings('tax_nexus', $value);
            return [];
        });
    }
    protected function taxType(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(get: function () {
            return $this->getFromSettings('tax_type');
        }, set: function (mixed $value) {
            $this->pushToSettings('tax_type', $value);
            return [];
        });
    }
    protected function taxCode(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(get: function () {
            return $this->getFromSettings('tax_code');
        }, set: function (mixed $value) {
            $this->pushToSettings('tax_code', $value);
            return [];
        });
    }
    /** @return array<string, string>|null */
    protected function currencies(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(get: function () {
            return $this->getFromSettings('currencies');
        }, set: function (?array $value) {
            $this->pushToSettings('currencies', $value);
            return [];
        });
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
