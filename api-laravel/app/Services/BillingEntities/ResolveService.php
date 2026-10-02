<?php

declare(strict_types=1);

namespace App\Services\BillingEntities;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' BillingEntities::ResolveService — resolves the billing
 * entity a customer should be attached to: by explicit code, else the
 * organization's default (oldest active) billing entity.
 */
class ResolveService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly ?string $billingEntityCode = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('billing_entity');

        if ($this->organization->billingEntities()->doesntExist()) {
            return $result->notFoundFailure('billing_entity');
        }

        if ($this->billingEntityCode !== null && $this->billingEntityCode !== '') {
            $billingEntity = $this->organization->billingEntities()
                ->where('code', $this->billingEntityCode)
                ->first();

            if ($billingEntity === null) {
                return $result->notFoundFailure('billing_entity');
            }

            $result->billing_entity = $billingEntity;

            return $result;
        }

        $result->billing_entity = $this->organization->defaultBillingEntity;

        return $result;
    }
}
