<?php

declare(strict_types=1);

use App\Models\CreditNote;
use App\Enums\CreditNoteCreditStatus;
use App\Services\CreditNotes\VoidService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\MethodNotAllowedFailure;

/**
 * Port of spec/services/credit_notes/void_service_spec.rb.
 */
it('voids the credit note', function (): void {
    $creditNote = CreditNote::factory()->create();

    $result = VoidService::call(creditNote: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->credit_note->creditStatusEnum())->toBe(CreditNoteCreditStatus::Voided)
        ->and($result->credit_note->voided_at)->not->toBeNull()
        ->and($result->credit_note->balance_amount_cents)->toBe(0);
})->group('ledger:svc:CreditNotes.VoidService');

it('returns not_found for a missing credit note', function (): void {
    $result = VoidService::call(creditNote: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
})->group('ledger:svc:CreditNotes.VoidService');

it('returns not_found for a draft credit note', function (): void {
    $creditNote = CreditNote::factory()->draft()->create();

    $result = VoidService::call(creditNote: $creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
})->group('ledger:svc:CreditNotes.VoidService');

it('rejects an already voided credit note', function (): void {
    $creditNote = CreditNote::factory()->create([
        'credit_status' => CreditNoteCreditStatus::Voided,
        'voided_at' => now(),
    ]);

    $result = VoidService::call(creditNote: $creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
        ->and($result->getError()->code)->toBe('no_voidable_amount');
})->group('ledger:svc:CreditNotes.VoidService');
