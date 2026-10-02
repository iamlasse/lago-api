<?php

declare(strict_types=1);

namespace App\Services\Taxes;

use App\Models\Tax;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' Taxes::CreateService (app/services/taxes/create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): BillingEntities::Taxes::ApplyTaxesService — applying the tax
 *   to the organization's default billing entity (billing_entities_taxes)
 *   when `applied_to_organization` is true; the emission point is marked
 *   below.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('tax');
        $organization = $this->organization;
        $params = $this->params;

        $tax = $organization->taxes()->make([
            'name' => $params['name'] ?? null,
            'code' => $params['code'] ?? null,
            'rate' => $params['rate'] ?? null,
            'description' => $params['description'] ?? null,
        ]);

        if (array_key_exists('applied_to_organization', $params)) {
            $tax->applied_to_organization = $params['applied_to_organization'];
        }

        try {
            $errors = $tax->validateAttributes();

            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $tax->save();

            if ($params['applied_to_organization'] ?? false) {
                $this->applyTaxesOnBillingEntity($tax);
            }

            $result->tax = $tax;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails: `apply_taxes_on_billing_entity` —
     * BillingEntities::Taxes::ApplyTaxesService.call(billing_entity:
     * organization.default_billing_entity, tax_codes: [code]).
     *
     * TODO(port): BillingEntities::Taxes::ApplyTaxesService.
     */
    protected function applyTaxesOnBillingEntity(Tax $tax): void
    {
        // TODO(port): BillingEntities::Taxes::ApplyTaxesService.call(
        //   billing_entity: $this->organization->defaultBillingEntity,
        //   tax_codes: [$tax->code]).
    }
}
