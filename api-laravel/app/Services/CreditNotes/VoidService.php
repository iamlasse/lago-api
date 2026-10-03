<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Services\BaseResult;

/**
 * Port of Rails' CreditNotes::VoidService
 * (app/services/credit_notes/void_service.rb).
 */
class VoidService extends \App\Services\BaseService
{
    public function __construct(private readonly ?CreditNote $creditNote) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note');

        if ($this->creditNote === null || $this->creditNote->isDraft()) {
            return $result->notFoundFailure('credit_note');
        }

        $result->credit_note = $this->creditNote;

        if (! $this->creditNote->voidable()) {
            return $result->notAllowedFailure('no_voidable_amount');
        }

        $this->creditNote->markAsVoided();

        return $result;
    }
}
