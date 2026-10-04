<?php

declare(strict_types=1);

// Port of Rails' config/locales/en/email.yml (the M1 mailer subset).
return [
    'invoice' => [
        'finalized' => [
            'download' => 'Download invoice for details',
            'due_date' => 'total due :date',
            'invoice_from' => 'Invoice from :billing_entity_name',
            'invoice_number' => 'Invoice Number',
            'issue_date' => 'Issue Date',
            'issued_on' => 'issued on :date',
            'subject' => 'Your Invoice from :billing_entity_name #:invoice_number',
        ],
    ],

    'credit_note' => [
        'created' => [
            'subject' => 'Your Credit note from :billing_entity_name #:credit_note_number',
        ],
    ],

    'payment_receipt' => [
        'created' => [
            'subject' => 'Your receipt from :billing_entity_name #:payment_receipt_number',
        ],
    ],

    'questions' => 'Questions? Contact us at',
    'powered_by' => 'Powered by',
];
