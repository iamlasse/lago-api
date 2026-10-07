<?php

declare(strict_types=1);

namespace App\Services\BillingEntities\Taxes;

use App\Models\BillingEntity;
use App\Services\BaseService as RootBaseService;
use App\Jobs\BillingEntities\Taxes\RefreshDraftInvoicesJob;

/**
 * Port of Rails' BillingEntities::Taxes::BaseService
 * (app/services/billing_entities/taxes/base_service.rb) — the shared
 * refresh hook: flag the billing entity's draft invoices for a refresh
 * after its applied taxes changed (async, like Rails' perform_later).
 */
abstract class BaseService extends RootBaseService
{
    public function __construct(
        protected readonly BillingEntity $billingEntity,
    ) {
        parent::__construct();
    }

    /**
     * Rails: `refresh_draft_invoices` —
     * BillingEntities::Taxes::RefreshDraftInvoicesJob.perform_later(billing_entity.id).
     */
    protected function refreshDraftInvoices(): void
    {
        dispatch(new \App\Jobs\BillingEntities\Taxes\RefreshDraftInvoicesJob($this->billingEntity->id));
    }
}
