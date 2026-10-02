<?php

declare(strict_types=1);

namespace App\Services\Taxes;

use App\Models\Tax;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;

use function array_unique;
use function array_values;
use function array_key_exists;

/**
 * Port of Rails' Taxes::UpdateService (app/services/taxes/update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): BillingEntities::Taxes::{Apply,Remove}TaxesService —
 *   maintaining the default billing entity's applied taxes when
 *   `applied_to_organization` changes; the hook point is marked below.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?Tax $tax,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('tax');
        $tax = $this->tax;
        $params = $this->params;

        if ($tax === null) {
            return $result->notFoundFailure('tax');
        }

        try {
            // NOTE: we must retrieve the list of applicable customers before
            // the update so the refresh covers both the previous and the new
            // set.
            $customerIds = $tax->applicableCustomers()->pluck('customers.id')->all();

            if (array_key_exists('name', $params)) {
                $tax->name = $params['name'];
            }
            if (array_key_exists('code', $params)) {
                $tax->code = $params['code'];
            }
            if (array_key_exists('rate', $params)) {
                $tax->rate = $params['rate'];
            }
            if (array_key_exists('description', $params)) {
                $tax->description = $params['description'];
            }
            if (array_key_exists('applied_to_organization', $params)) {
                $tax->applied_to_organization = $params['applied_to_organization'];
            }

            $errors = $tax->validateAttributes();

            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $tax->save();

            if (array_key_exists('applied_to_organization', $params)) {
                $this->manageTaxesOnBillingEntity($tax);
            }

            $customerIds = array_values(array_unique([
                ...$customerIds,
                ...$tax->refresh()->applicableCustomers()->pluck('customers.id')->all(),
            ]));

            // Rails: organization.invoices.where(customer_id:).draft
            //   .update_all(ready_to_be_refreshed: true).
            $tax->organization->invoices()
                ->whereIn('customer_id', $customerIds)
                ->where('status', 0) // Rails: Invoice.draft (draft: 0)
                ->update(['ready_to_be_refreshed' => true]);

            $result->tax = $tax;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails: `manage_taxes_on_billing_entity` — apply or remove the tax on
     * the organization's default billing entity to mirror
     * `applied_to_organization`.
     *
     * TODO(port): BillingEntities::Taxes::{Apply,Remove}TaxesService.
     */
    protected function manageTaxesOnBillingEntity(Tax $tax): void
    {
        // TODO(port): when $tax->applied_to_organization —
        //   BillingEntities::Taxes::ApplyTaxesService.call(
        //     billing_entity: $tax->organization->defaultBillingEntity,
        //     tax_codes: [$tax->code]); otherwise the matching
        //   BillingEntities::Taxes::RemoveTaxesService.call.
    }
}
