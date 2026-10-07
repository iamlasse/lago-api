<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DataExport;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Port of Rails' DataExportMailer#completed (app/mailers/data_export_mailer.rb
 * + app/views/data_export_mailer/completed.slim): the "export is ready"
 * email with the download link.
 */
class DataExportCompletedMail extends Mailable
{
    public function __construct(
        public readonly DataExport $dataExport,
    ) {}

    /** Rails: @resource_type = data_export.resource_type.humanize.downcase. */
    public function resourceType(): string
    {
        return mb_strtolower(str_replace('_', ' ', (string) $this->dataExport->resource_type));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: config('lago.from_email'),
            subject: __('email.data_export.completed.subject', [
                'resource_type' => $this->resourceType(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.data_export.completed',
            with: [
                'dataExport' => $this->dataExport,
                'resourceType' => $this->resourceType(),
            ],
        );
    }
}
