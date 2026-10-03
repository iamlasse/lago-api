<?php

declare(strict_types=1);

use App\Models\CreditNote;
use App\Enums\CreditNoteRefundStatus;
use App\Services\Failures\NotFoundFailure;
use App\Services\CreditNotes\UpdateService;

/**
 * Port of spec/services/credit_notes/update_service_spec.rb (the scenarios
 * this slice ports — metadata lives with the Metadata slice, TODO(port)).
 */
it('updates the refund status', function (): void {
    $creditNote = CreditNote::factory()->create();

    $result = UpdateService::call(creditNote: $creditNote, params: ['refund_status' => 'succeeded']);

    expect($result->success())->toBeTrue()
        ->and($result->credit_note->refundStatusEnum())->toBe(CreditNoteRefundStatus::Succeeded)
        ->and($result->credit_note->refunded_at)->not->toBeNull();
})->group('ledger:svc:CreditNotes.UpdateService');

it('updates the refund status to pending without refunded_at', function (): void {
    $creditNote = CreditNote::factory()->create();

    $result = UpdateService::call(creditNote: $creditNote, params: ['refund_status' => 'pending']);

    expect($result->success())->toBeTrue()
        ->and($result->credit_note->refundStatusEnum())->toBe(CreditNoteRefundStatus::Pending)
        ->and($result->credit_note->refunded_at)->toBeNull();
})->group('ledger:svc:CreditNotes.UpdateService');

it('rejects an invalid refund status', function (): void {
    $creditNote = CreditNote::factory()->create();

    $result = UpdateService::call(creditNote: $creditNote, params: ['refund_status' => 'foo_bar']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['refund_status' => ['value_is_invalid']]);
})->group('ledger:svc:CreditNotes.UpdateService');

it('returns not_found for a missing credit note', function (): void {
    $result = UpdateService::call(creditNote: null, params: ['refund_status' => 'succeeded']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
})->group('ledger:svc:CreditNotes.UpdateService');

it('returns not_found for a draft credit note', function (): void {
    $creditNote = CreditNote::factory()->draft()->create();

    $result = UpdateService::call(creditNote: $creditNote, params: ['refund_status' => 'succeeded']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
})->group('ledger:svc:CreditNotes.UpdateService');

it('does not change anything without params', function (): void {
    $creditNote = CreditNote::factory()->create();
    $before = $creditNote->updated_at;

    $result = UpdateService::call(creditNote: $creditNote, params: []);

    expect($result->success())->toBeTrue()
        ->and($creditNote->refresh()->updated_at->equalTo($before))->toBeTrue();
})->group('ledger:svc:CreditNotes.UpdateService');
