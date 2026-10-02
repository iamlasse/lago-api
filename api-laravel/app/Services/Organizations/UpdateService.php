<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\BillingEntities\UpdateService as BillingEntitiesUpdateService;

use function is_array;
use function array_key_exists;

/**
 * Port of Rails' Organizations::UpdateService
 * (app/services/organizations/update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): handle_base64_logo — ActiveStorage logo attachments.
 * - TODO(port): authentication_methods change mailer (no mailer infra).
 * - TODO(port): ApiKeys::CacheService.expire_all_cache (cache invalidation
 *   hook, arrives with the API-key cache task).
 * - TODO(port): BillingEntities eu_tax_management auto-generate + invoice
 *   grace period job (see BillingEntities\UpdateService).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly object $organization,
        private readonly array $params,
        private readonly ?object $user = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('organization');

        return $this->rescueFailures(function () use ($result): BaseResult {
            $organization = $this->organization;
            $params = $this->params;

            // Rails assigns attributes before opening the transaction, then
            // saves — an invalid attribute set leaves the in-memory model
            // dirty but nothing persisted.
            if (array_key_exists('email', $params)) {
                $organization->email = $params['email'];
            }
            if (array_key_exists('legal_name', $params)) {
                $organization->legal_name = $params['legal_name'];
            }
            if (array_key_exists('legal_number', $params)) {
                $organization->legal_number = $params['legal_number'];
            }
            if (array_key_exists('tax_identification_number', $params)) {
                $organization->tax_identification_number = $params['tax_identification_number'];
            }
            if (array_key_exists('address_line1', $params)) {
                $organization->address_line1 = $params['address_line1'];
            }
            if (array_key_exists('address_line2', $params)) {
                $organization->address_line2 = $params['address_line2'];
            }
            if (array_key_exists('zipcode', $params)) {
                $organization->zipcode = $params['zipcode'];
            }
            if (array_key_exists('city', $params)) {
                $organization->city = $params['city'];
            }
            if (array_key_exists('state', $params)) {
                $organization->state = $params['state'];
            }
            if (array_key_exists('country', $params)) {
                $organization->country = $params['country'] !== null ? mb_strtoupper($params['country']) : null;
            }
            if (array_key_exists('default_currency', $params)) {
                $organization->default_currency = $params['default_currency'] !== null
                    ? mb_strtoupper($params['default_currency'])
                    : null;
            }
            if (array_key_exists('document_number_prefix', $params)) {
                $organization->document_number_prefix = $params['document_number_prefix'];
            }
            if (array_key_exists('slug', $params)) {
                $organization->slug = $params['slug'] !== null
                    ? mb_strtolower(mb_trim($params['slug']))
                    : null;
            }
            if (array_key_exists('finalize_zero_amount_invoice', $params)) {
                $organization->finalize_zero_amount_invoice = $params['finalize_zero_amount_invoice'];
            }
            if (array_key_exists('net_payment_term', $params)) {
                $organization->net_payment_term = $params['net_payment_term'];
            }
            if (array_key_exists('document_numbering', $params)) {
                // Rails: the enum takes its NAME ('per_customer' |
                // 'per_organization') and stores the integer position; the
                // enum's own validation rejects any other name before save.
                $mapped = is_int($params['document_numbering'])
                    ? $params['document_numbering']
                    : (Organization::DOCUMENT_NUMBERINGS[$params['document_numbering']] ?? null);

                if ($mapped === null) {
                    $result->validationFailure(['document_numbering' => ['value_is_invalid']])->raiseIfError();
                }

                $organization->document_numbering = $mapped;
            }
            if (array_key_exists('authentication_methods', $params)) {
                // TODO(port): OrganizationMailer authentication_methods_updated —
                // delivered after commit when the list changed and a user is
                // given.
                $organization->authentication_methods = $params['authentication_methods'];
            }

            $billing = $params['billing_configuration'] ?? [];
            $billing = is_array($billing) ? $billing : [];

            if (array_key_exists('invoice_footer', $billing)) {
                $organization->invoice_footer = $billing['invoice_footer'];
            }
            if (array_key_exists('document_locale', $billing)) {
                $organization->document_locale = $billing['document_locale'];
            }

            DB::transaction(function () use ($organization, $params, $billing, $result): void {
                if (array_key_exists('eu_tax_management', $params)) {
                    $this->handleEuTaxManagement($organization, $params['eu_tax_management']);
                }

                if (array_key_exists('webhook_url', $params)) {
                    $webhookEndpoint = $organization->webhookEndpoints()->firstOrNew([]);

                    $webhookEndpoint->webhook_url = $params['webhook_url'];
                    $webhookEndpoint->save();
                }

                if ($this->premium() && array_key_exists('invoice_grace_period', $billing)) {
                    // Rails: premium-only, org-level grace period. TODO(port):
                    // the related invoices' due dates are handled at the
                    // billing_entity level once the invoice jobs exist.
                    $organization->invoice_grace_period = $billing['invoice_grace_period'];
                }

                if (array_key_exists('logo', $params)) {
                    // TODO(port): handle_base64_logo — needs ActiveStorage-
                    // equivalent logo attachments.
                }

                $this->assignPremiumAttributes($organization, $params);

                $errors = $organization->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $organization->save();

                $updateBillingEntityResult = BillingEntitiesUpdateService::call(
                    billingEntity: $organization->defaultBillingEntity,
                    params: $params,
                );

                $updateBillingEntityResult->raiseIfError();

                if ($updateBillingEntityResult->billing_entity !== null) {
                    // Keep the org's cached relation coherent for the caller.
                    $organization->setRelation('defaultBillingEntity', $updateBillingEntityResult->billing_entity);
                }
            });

            // TODO(port): ApiKeys::CacheService.expire_all_cache(organization)

            $result->organization = $organization;

            return $result;
        }, $result);
    }

    /**
     * Rails: `handle_eu_tax_management` — assigns the flag and, when truthy,
     * requires the organization to be in an EU (VAT) country.
     */
    protected function handleEuTaxManagement(object $organization, mixed $euTaxManagement): void
    {
        $organization->eu_tax_management = $euTaxManagement;

        if (! $euTaxManagement) {
            return;
        }

        if (! $organization->euVatEligible()) {
            static::makeResult('organization')
                ->singleValidationFailure('org_must_be_in_eu', 'eu_tax_management')
                ->raiseIfError();
        }
    }

    /** Rails: `assign_premium_attributes` — timezone + email_settings. */
    protected function assignPremiumAttributes(object $organization, array $params): void
    {
        if (! $this->premium()) {
            return;
        }

        if (array_key_exists('timezone', $params)) {
            $organization->timezone = $params['timezone'];
        }
        if (array_key_exists('email_settings', $params)) {
            $organization->email_settings = $params['email_settings'];
        }
    }
}
