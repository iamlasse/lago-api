<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Services\BaseResult;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use App\Enums\CreditNoteRefundStatus;

/**
 * Port of Rails' CreditNotes::UpdateService
 * (app/services/credit_notes/update_service.rb).
 *
 * Draft credit notes answer not_found (Rails:
 * `return result.not_found_failure!(resource: "credit_note") if ... credit_note.draft?`).
 * TODO(port): metadata (Metadata::ItemMetadata is not ported) and the
 * Segment refund-status track.
 */
class UpdateService extends \App\Services\BaseService
{
    /**
     * @param  array<string, mixed>  $params  Rails' **params — refund_status, metadata.
     */
    public function __construct(
        private readonly ?CreditNote $creditNote,
        private readonly array $params = [],
        private readonly bool $partialMetadata = false,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note');

        if ($this->creditNote === null || $this->creditNote->isDraft()) {
            return $result->notFoundFailure('credit_note');
        }

        try {
            DB::transaction(function (): void {
                if (array_key_exists('refund_status', $this->params)) {
                    // Rails assigns the enum NAME (invalid names raise
                    // ArgumentError, rescued into the value_is_invalid
                    // validation failure).
                    $status = CreditNoteRefundStatus::fromOption($this->params['refund_status']);

                    if ($status === null) {
                        throw new InvalidArgumentException('invalid refund_status');
                    }

                    $this->creditNote->refund_status = $status;
                    if ($status === CreditNoteRefundStatus::Succeeded->value) {
                        $this->creditNote->refunded_at = now();
                    }
                }

                // TODO(port): update_metadata! (Metadata::ItemMetadata).

                if ($this->creditNote->isDirty()) {
                    $this->creditNote->save();
                }
            });
        } catch (InvalidArgumentException) {
            return $result->singleValidationFailure('value_is_invalid', field: 'refund_status');
        } catch (\Illuminate\Database\QueryException $e) {
            return $result->validationFailure(['base' => [$e->getMessage()]]);
        }

        // Rails: handle_changes — Segment track on refund_status change (TODO(port)).

        $result->credit_note = $this->creditNote;

        return $result;
    }
}
