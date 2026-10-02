<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;

/**
 * Port of Api::ForbidsLegacyBilling
 * (app/controllers/concerns/api/forbids_legacy_billing.rb) — the plan
 * subresource write actions are forbidden once the organization switched to
 * the product-catalog billing engine.
 *
 * Checked against the action name: several hosts define only a subset of
 * these actions, so callers pass the current action explicitly.
 */
trait ForbidsLegacyBilling
{
    protected function forbidLegacyBilling(string $action): void
    {
        if (! in_array($action, ['create', 'update', 'destroy'], true)) {
            return;
        }

        /** @var ApiController $this */
        $organization = $this->currentOrganization();

        if ($organization !== null && in_array('product_catalog', (array) ($organization->feature_flags ?? []), true)) {
            throw new ForbiddenException('legacy_billing_disabled');
        }
    }
}
