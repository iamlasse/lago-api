<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Services\BaseResult;
use App\Jobs\SendWebhookJob;

/**
 * Port of Rails' Invoices::LoseDisputeService
 * (app/services/invoices/lose_dispute_service.rb).
 *
 * TODO(port) emission points left at their exact Rails positions:
 * activity_loggable, Invoices::ProviderTaxes::VoidJob, the Hubspot update
 * job, and the "invoice.payment_dispute_lost" webhook type registration
 * (SendWebhookJob::WEBHOOK_SERVICES covers the M1 surface only).
 */
class LoseDisputeService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Invoice $invoice,
        private readonly ?string $paymentDisputeLostAt = null,
        private readonly ?string $reason = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        $result->invoice = $this->invoice;

        // Rails: mark_as_dispute_lost! — payment_dispute_losable? is an
        // absence-validation guard (finalized || voided); the save raises
        // RecordInvalid -> not_disputable otherwise.
        if (! $this->invoice->isFinalized() && ! $this->invoice->isVoided()) {
            return $result->notAllowedFailure('not_disputable');
        }

        $this->invoice->payment_dispute_lost_at ??= now();
        $this->invoice->payment_overdue = false;
        $this->invoice->save();

        SendWebhookJob::performLater('invoice.payment_dispute_lost', $result->invoice, [
            'provider_error' => $this->reason,
        ]);

        // TODO(port): Invoices::ProviderTaxes::VoidJob.perform_later(invoice:)
        // and the Hubspot update job.

        return $result;
    }
}
