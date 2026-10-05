<?php

declare(strict_types=1);

namespace App\Services\BillingEntities;

use App\Support\License;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' BillingEntities::CreateService
 * (app/services/billing_entities/create_service.rb): creates a billing
 * entity on the organization, gated by the multi-entities allowance, with
 * the billing-configuration block and the premium-only settings applied
 * inside the create transaction.
 */
class CreateService extends BaseService
{
    /** Rails: `create_attributes` — params.slice(*%i[...]) (create_service.rb:60). */
    private const CREATE_ATTRIBUTES = [
        'address_line1',
        'address_line2',
        'city',
        'code',
        'country',
        'default_currency',
        'document_number_prefix',
        'document_numbering',
        'email',
        'finalize_zero_amount_invoice',
        'legal_name',
        'legal_number',
        'name',
        'net_payment_term',
        'phone',
        'state',
        'tax_identification_number',
        'vat_rate',
        'zipcode',
    ];

    public function __construct(
        private readonly Organization $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('billing_entity');

        // Rails: `return result.forbidden_failure! unless
        // organization.can_create_billing_entity?` (default code
        // "feature_unavailable").
        if (! $this->organization->canCreateBillingEntity()) {
            return $result->forbiddenFailure();
        }

        $billingEntity = $this->organization->billingEntities()->make();
        $params = $this->params;

        // Rails: assign_attributes(create_attributes) — the sliced scalar
        // params (create_service.rb:60-82).
        foreach (self::CREATE_ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $params)) {
                $billingEntity->{$attribute} = $params[$attribute];
            }
        }

        // Rails: `billing_entity.id = params[:id] if params[:id]`.
        if (! empty($params['id'])) {
            $billingEntity->id = $params['id'];
        }

        // Rails: invoice_footer always from billing_configuration;
        // document_locale only when present there.
        $billingConfiguration = is_array($params['billing_configuration'] ?? null)
            ? $params['billing_configuration']
            : [];

        $billingEntity->invoice_footer = $billingConfiguration['invoice_footer'] ?? null;

        if (array_key_exists('document_locale', $billingConfiguration)) {
            $billingEntity->document_locale = $billingConfiguration['document_locale'];
        }

        if (array_key_exists('einvoicing', $params)) {
            $billingEntity->einvoicing = $params['einvoicing'];
        }

        if (array_key_exists('eu_tax_management', $params)) {
            // TODO(port): BillingEntities::ChangeEuTaxManagementService —
            // validates EU eligibility and auto-generates the lago_eu_* taxes
            // (same deviation as BillingEntities\UpdateService).
            $billingEntity->eu_tax_management = $params['eu_tax_management'];
        }

        // TODO(port): handle_base64_logo — ActiveStorage attachment
        // (io/filename/content_type); the port has no attachment store yet.

        if (License::premium()) {
            if (! empty($billingConfiguration['invoice_grace_period'])) {
                $billingEntity->invoice_grace_period = $billingConfiguration['invoice_grace_period'];
            }
            if (! empty($params['timezone'])) {
                $billingEntity->timezone = $params['timezone'];
            }
            if (! empty($params['email_settings'])) {
                $billingEntity->email_settings = $params['email_settings'];
            }
            if (! empty($billingConfiguration['subscription_invoice_issuing_date_anchor'])) {
                $billingEntity->subscription_invoice_issuing_date_anchor =
                    $billingConfiguration['subscription_invoice_issuing_date_anchor'];
            }
            if (! empty($billingConfiguration['subscription_invoice_issuing_date_adjustment'])) {
                $billingEntity->subscription_invoice_issuing_date_adjustment =
                    $billingConfiguration['subscription_invoice_issuing_date_adjustment'];
            }
        }

        // Rails: billing_entity.save! raises RecordInvalid on validation
        // failure -> record_validation_failure!(record:).
        $errors = $billingEntity->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $billingEntity->save();

        // TODO(port): track_billing_entity_created (SegmentTrackJob) and
        // register_security_log (Utils::SecurityLog.produce).

        $result->billing_entity = $billingEntity;

        return $result;
    }
}
