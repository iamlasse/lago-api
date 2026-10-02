<?php

declare(strict_types=1);

namespace App\Services\BillingEntities;

use App\Services\BaseResult;
use App\Models\BillingEntity;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Enums\EntityDocumentNumbering;

use function is_array;
use function array_key_exists;

/**
 * Port of Rails' BillingEntities::UpdateService (app/services/billing_entities/
 * update_service.rb), minus the pieces whose dependencies do not exist yet
 * (marked TODO(port)).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?BillingEntity $billingEntity,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('billing_entity');
        $billingEntity = $this->billingEntity;
        $params = $this->params;

        if ($billingEntity === null || ! $billingEntity->exists) {
            return $result->notFoundFailure('billing_entity');
        }

        if (array_key_exists('name', $params)) {
            $billingEntity->name = $params['name'];
        }
        if (array_key_exists('einvoicing', $params)) {
            $billingEntity->einvoicing = $params['einvoicing'];
        }
        if (array_key_exists('email', $params)) {
            $billingEntity->email = $params['email'];
        }
        if (array_key_exists('legal_name', $params)) {
            $billingEntity->legal_name = $params['legal_name'];
        }
        if (array_key_exists('legal_number', $params)) {
            $billingEntity->legal_number = $params['legal_number'];
        }
        if (array_key_exists('tax_identification_number', $params)) {
            $billingEntity->tax_identification_number = $params['tax_identification_number'];
        }
        if (array_key_exists('address_line1', $params)) {
            $billingEntity->address_line1 = $params['address_line1'];
        }
        if (array_key_exists('address_line2', $params)) {
            $billingEntity->address_line2 = $params['address_line2'];
        }
        if (array_key_exists('phone', $params)) {
            $billingEntity->phone = $params['phone'];
        }
        if (array_key_exists('zipcode', $params)) {
            $billingEntity->zipcode = $params['zipcode'];
        }
        if (array_key_exists('city', $params)) {
            $billingEntity->city = $params['city'];
        }
        if (array_key_exists('state', $params)) {
            $billingEntity->state = $params['state'];
        }
        if (array_key_exists('country', $params)) {
            $billingEntity->country = $params['country'] !== null ? mb_strtoupper($params['country']) : null;
        }
        if (array_key_exists('default_currency', $params)) {
            $billingEntity->default_currency = $params['default_currency'] !== null ? mb_strtoupper($params['default_currency']) : null;
        }

        return DB::transaction(function () use ($billingEntity, $params, $result): BaseResult {
            if (array_key_exists('document_numbering', $params)) {
                // Rails maps the organization-level value onto the entity:
                // 'per_customer' stays, anything else becomes
                // 'per_billing_entity'.
                $billingEntity->document_numbering = $params['document_numbering'] === 'per_customer'
                    ? EntityDocumentNumbering::PerCustomer
                    : EntityDocumentNumbering::PerBillingEntity;

                // TODO(port): BillingEntities::ChangeInvoiceNumberingService —
                // renumbers invoices when switching numbering modes.
            }

            if (array_key_exists('document_number_prefix', $params)) {
                $billingEntity->document_number_prefix = $params['document_number_prefix'];
            }
            if (array_key_exists('finalize_zero_amount_invoice', $params)) {
                $billingEntity->finalize_zero_amount_invoice = $params['finalize_zero_amount_invoice'];
            }

            $billing = $params['billing_configuration'] ?? [];
            $billing = is_array($billing) ? $billing : [];

            if (array_key_exists('invoice_footer', $billing)) {
                $billingEntity->invoice_footer = $billing['invoice_footer'];
            }
            if (array_key_exists('document_locale', $billing)) {
                $billingEntity->document_locale = $billing['document_locale'];
            }

            if (array_key_exists('eu_tax_management', $params)) {
                // TODO(port): BillingEntities::ChangeEuTaxManagementService —
                // validates EU eligibility and auto-generates the lago_eu_*
                // taxes on the billing entity.
                $billingEntity->eu_tax_management = $params['eu_tax_management'];
            }

            if (array_key_exists('net_payment_term', $params)) {
                // Rails: only assigns the new term, the save below persists
                // it; due-date recomputation on invoices is a separate
                // service that is not ported yet.
                // TODO(port): BillingEntities::UpdateInvoicePaymentDueDateService.
                $billingEntity->net_payment_term = $params['net_payment_term'];
            }

            if (array_key_exists('tax_codes', $params)) {
                // TODO(port): BillingEntities::Taxes::ManageTaxesService —
                // needs the BillingEntity::AppliedTax model.
            }

            $this->assignPremiumAttributes($billingEntity, $params);

            $errors = $billingEntity->validateAttributes();

            if ($errors !== []) {
                return $result->recordValidationFailure($errors);
            }

            $billingEntity->save();

            $result->billing_entity = $billingEntity;

            return $result;
        });
    }

    /**
     * Rails: `assign_premium_attributes` — timezone + email_settings are
     * premium-gated attributes.
     */
    protected function assignPremiumAttributes(BillingEntity $billingEntity, array $params): void
    {
        if (! $this->premium()) {
            return;
        }

        if (array_key_exists('timezone', $params)) {
            $billingEntity->timezone = $params['timezone'];
        }
        if (array_key_exists('email_settings', $params)) {
            $billingEntity->email_settings = $params['email_settings'];
        }
    }
}
