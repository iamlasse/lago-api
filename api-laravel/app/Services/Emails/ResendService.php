<?php

declare(strict_types=1);

namespace App\Services\Emails;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentReceipt;
use App\Mail\PaymentReceiptCreatedMail;
use App\Services\Validators\EmailSanitizer;

/**
 * Port of Rails' Emails::ResendService (app/services/emails/resend_service.rb)
 * — the POST /resend_email endpoints (Rails: `*`/resend_email).
 *
 * Scope of this slice: PaymentReceipt (the only resend target whose
 * controller is ported). Rails' Invoice / CreditNote branches share the same
 * preconditions — they arrive with those controllers' slices.
 *
 * Rails order: resource present, valid_status? (a PaymentReceipt is always
 * "finalized"), premium license, free-form validation errors (billing entity
 * email configured, sender configured, at least one valid recipient). Then
 * the mailer is built with the to/cc/bcc overrides and delivered.
 *
 * TODO(port): the Invoice / CreditNote mailer branches (InvoiceMailer /
 * CreditNoteMailer with their ensure-PDF preconditions and the
 * zero-amount-invoice rule) once those resenders are ported. Rails delivers
 * through deliver_later (SendEmailJob); the port sends inline, like the
 * invoice NotifyJob.
 */
class ResendService extends BaseService
{
    public function __construct(
        private readonly ?object $resource,
        private readonly ?array $to = null,
        private readonly ?array $cc = null,
        private readonly ?array $bcc = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        $resourceType = $this->resource === null
            ? 'resource'
            : mb_strtolower(class_basename($this->resource));

        if ($this->resource === null) {
            return $result->notFoundFailure($resourceType);
        }

        // Rails: not_allowed_failure!(code: "#{resource_type}_not_finalized")
        // unless valid_status?.
        if (! $this->resource instanceof PaymentReceipt) {
            return $result->notAllowedFailure($resourceType.'_not_finalized');
        }

        if (! $this->premium()) {
            return $result->forbiddenFailure('premium_license_required');
        }

        $validationErrors = $this->validationErrors();

        if ($validationErrors !== []) {
            return $result->validationFailure($validationErrors);
        }

        $mailable = new PaymentReceiptCreatedMail(
            $this->resource,
            resend: true,
            recipientTo: $this->recipientsTo(),
            recipientCc: $this->recipientsCc(),
            recipientBcc: $this->recipientsBcc(),
        );

        // Rails: PaymentReceiptMailer.with(...).created.deliver_later — the
        // mailer's own guards (no billing entity email / recipients) answer
        // no delivery instead of a failure.
        if ($mailable->shouldSend()) {
            $mailable->send();
        }

        return $result;
    }

    /** @return list<string> */
    private function recipientsTo(): array
    {
        if ($this->to !== null && $this->to !== []) {
            return array_values($this->to);
        }

        return array_values(array_filter([(string) ($this->resource->customer()?->email ?? '')]));
    }

    /** @return list<string> */
    private function recipientsCc(): array
    {
        return array_values((array) ($this->cc ?? []));
    }

    /** @return list<string> */
    private function recipientsBcc(): array
    {
        return array_values((array) ($this->bcc ?? []));
    }

    /** @return array<string, list<string>> */
    private function validationErrors(): array
    {
        $errors = [];

        $billingEntity = $this->resource->billingEntity;

        if ($billingEntity === null || ($billingEntity->email ?? '') === '') {
            $errors['billing_entity'] = ['must have email configured'];
        }

        if (($billingEntity?->fromEmailAddress() ?? '') === '') {
            $errors['from'] = ['must have a sender email address configured'];
        }

        if ($this->recipientsTo() === []) {
            $errors['to'] = ['must have at least one recipient'];
        }

        $invalidTo = $this->invalidEmails($this->recipientsTo());

        if ($invalidTo !== []) {
            $errors['to'] = ['invalid email format: '.implode(', ', $invalidTo)];
        }

        $invalidCc = $this->invalidEmails($this->recipientsCc());

        if ($invalidCc !== []) {
            $errors['cc'] = ['invalid email format: '.implode(', ', $invalidCc)];
        }

        $invalidBcc = $this->invalidEmails($this->recipientsBcc());

        if ($invalidBcc !== []) {
            $errors['bcc'] = ['invalid email format: '.implode(', ', $invalidBcc)];
        }

        return $errors;
    }

    /** @param list<string> $emails @return list<string> */
    private function invalidEmails(array $emails): array
    {
        return array_values(array_filter(
            $emails,
            fn (string $email): bool => EmailSanitizer::valid($email) === false,
        ));
    }
}
