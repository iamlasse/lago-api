<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\PostgresArray;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `dunning_campaigns` (Rails' DunningCampaign).
 *
 * SoftDeletes == Rails' Discard (deleted_at, default_scope -> { kept }).
 * Rails ignores the `applied_to_organization` column in the model
 * (self.ignored_columns) — the port keeps reading the column for the legacy
 * GraphQL field, like Rails' Types layer still does.
 */
#[Fillable([
    'organization_id',
    'name',
    'code',
    'description',
    'applied_to_organization',
    'days_between_attempts',
    'max_attempts',
    'bcc_emails',
])]
#[Table(name: 'dunning_campaigns')]
class DunningCampaign extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /** Rails: DunningCampaign::ORDERS — the GraphQL list order whitelist. */
    public const ORDERS = ['name', 'code'];

    /** Rails: belongs_to :organization. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: has_many :thresholds, dependent: :destroy (discarded on destroy). */
    public function thresholds(): HasMany
    {
        return $this->hasMany(DunningCampaignThreshold::class);
    }

    /** Rails: has_many :customers, foreign_key: :applied_dunning_campaign_id. */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'applied_dunning_campaign_id');
    }

    /** Rails: has_many :billing_entities, foreign_key: :applied_dunning_campaign_id. */
    public function billingEntities(): HasMany
    {
        return $this->hasMany(BillingEntity::class, 'applied_dunning_campaign_id');
    }

    /** Rails: has_many :payment_requests, dependent: :nullify. */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    /**
     * Port of DunningCampaign#reset_customers_last_attempt — reset the
     * attempt bookkeeping of the explicitly-applied customers and of the
     * customers falling back to the billing entities carrying this campaign.
     */
    public function resetCustomersLastAttempt(): void
    {
        // Rails: customers.update_all(...) — no validations/touches.
        $this->customers()->toBase()->update([
            'dunning_currency_attempts' => '{}',
            'last_dunning_campaign_attempt' => 0,
            'last_dunning_campaign_attempt_at' => null,
        ]);

        // Customers falling back to the billing_entity campaign.
        Customer::query()
            ->whereNull('applied_dunning_campaign_id')
            ->where('exclude_from_dunning_campaign', false)
            ->whereIn('billing_entity_id', $this->billingEntities()->select('id'))
            ->toBase()->update([
                'dunning_currency_attempts' => '{}',
                'last_dunning_campaign_attempt' => 0,
                'last_dunning_campaign_attempt_at' => null,
            ]);
    }

    // -- Validations (port of the Rails model errors) --------------------------

    /**
     * Port of the Rails validations — name presence, bcc_emails email
     * format, positive days_between_attempts / max_attempts, org-scoped code
     * uniqueness over kept rows. Returns field => [codes].
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->name ?? '') === '') {
            // Rails: validates :name, presence: true.
            $errors['name'] = ['value_is_mandatory'];
        }

        foreach ((array) ($this->bcc_emails ?? []) as $email) {
            if (\App\Services\Validators\EmailSanitizer::valid((string) $email) === false) {
                // Rails: validates :bcc_emails, email_array: true.
                $errors['bcc_emails'] = ['value_is_invalid'];

                break;
            }
        }

        if ((int) $this->days_between_attempts <= 0) {
            // Rails: numericality {greater_than: 0}.
            $errors['days_between_attempts'] = ['must_be_greater_than_zero'];
        }

        if ((int) $this->max_attempts <= 0) {
            // Rails: numericality {greater_than: 0}.
            $errors['max_attempts'] = ['must_be_greater_than_zero'];
        }

        if (($this->code ?? '') !== '') {
            // Rails: uniqueness {conditions: -> { where(deleted_at: nil) },
            // scope: :organization_id} (backed by a partial unique index).
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

    protected function casts(): array
    {
        return [
            'days_between_attempts' => 'integer',
            'max_attempts' => 'integer',
            'applied_to_organization' => 'boolean',
            'bcc_emails' => PostgresArray::class,
        ];
    }
}
