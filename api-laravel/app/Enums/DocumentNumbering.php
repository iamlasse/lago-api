<?php

namespace App\Enums;

/**
 * organizations.document_numbering — integer column, Rails enum order is the
 * stored value (0-based). Never renumber.
 */
enum DocumentNumbering: int
{
    case PerCustomer = 0;
    case PerOrganization = 1;
}
