<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\CreditNotes;

use App\Models\CreditNote;
use App\Services\BaseResult;
use App\Services\Integrations\Aggregator\BaseService;
use App\Jobs\Integrations\Aggregator\CreditNotes\CreateJob;

/**
 * Port of Rails' Integrations::Aggregator::CreditNotes::CreateService
 * (app/services/integrations/aggregator/credit_notes/create_service.rb) —
 * the collector resolves its integration through the credit note customer's
 * first accounting-kind integration customer.
 *
 * TODO(port): the synchronous `call` leg — the credit-note payloads
 * (Integrations::Aggregator::CreditNotes::Payloads::Factory and the
 * Netsuite/Xero bodies) are not ported yet; the async entrypoint the
 * `syncIntegrationCreditNote` mutation drives is live and the job runs the
 * guards below until the payloads slice lands.
 */
class CreateService extends BaseService
{
    public function __construct(
        public readonly ?CreditNote $credit_note,
    ) {
        // Rails: super(invoice: credit_note.invoice) — the invoice collector
        // base resolves the integration off the customer; the port binds the
        // credit note's customer accounting integration directly.
        parent::__construct(
            $this->credit_note?->customer?->integrationCustomers()->accountingKind()->first()?->integration,
        );
    }

    public function actionPath(): string
    {
        return 'v1/'.$this->provider().'/creditnotes';
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note_id', 'external_id');

        // Rails: call guards — no integration, sync disabled, non-finalized
        // or already-synced credit notes answer without a payload. The
        // payload push itself is TODO(port), see the class docblock.
        if ($this->integration === null) {
            return $result;
        }

        if (! $this->integration->getFromSettings('sync_credit_notes')) {
            return $result;
        }

        if ($this->credit_note === null || ! $this->credit_note->isFinalized()) {
            return $result;
        }

        return $result;
    }

    /** Rails: `call_async` — the CreateJob enqueue with the credit note id. */
    public function call_async(): BaseResult
    {
        $result = BaseResult::of('credit_note_id', 'external_id');

        if ($this->credit_note === null) {
            return $result->notFoundFailure('credit_note');
        }

        CreateJob::dispatch($this->credit_note);

        $result->credit_note_id = $this->credit_note->id;

        return $result;
    }
}
