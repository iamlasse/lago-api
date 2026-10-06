<?php

declare(strict_types=1);

// Port of Rails' config/locales/en/credit_note.yml (the keys the ported
// credit-note document template renders).
return [
    'amount' => 'Amount (excl. tax)',
    'coupon_adjustment' => 'Coupons',
    'credit_from' => 'From',
    'credit_note_number' => 'Credit note number',
    'credit_to' => 'Credit to',
    'document_name' => 'Credit note',
    'invoice_number' => 'Invoice number',
    'issue_date' => 'Issue date',
    'issued_notice' => 'Issued on :issuing_date',
    'item' => 'Item',
    'offset_invoice' => 'Offset on invoice :invoice_number',
    'purchase_order_number' => 'Purchase order number',
    'refunded' => 'Refunded',
    'self_billed' => [
        'footer' => 'This credit note on a self-billing invoice was issued by the client on behalf of the partner, with their consent. The partner has agreed not to issue their own credit note for this transaction.',
    ],
    'sub_total_without_tax' => 'Sub total (excl. tax)',
    'tax' => 'Tax',
    'tax_rate' => 'Tax rate',
    'total' => 'Total',
];
