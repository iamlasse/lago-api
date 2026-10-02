<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * billing_entities.document_numbering — native Postgres enum
 * `entity_document_numbering` ('per_customer', 'per_billing_entity').
 * (organizations.document_numbering is the *integer* variant — see
 * DocumentNumbering.)
 */
enum EntityDocumentNumbering: string
{
    case PerCustomer = 'per_customer';
    case PerBillingEntity = 'per_billing_entity';
}
