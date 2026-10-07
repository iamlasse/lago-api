<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * invoice_custom_sections.section_type — Postgres native enum
 * `invoice_custom_section_type` ('manual', 'system_generated'), Rails
 * `enum :section_type, SECTION_TYPES, default: :manual`
 * (app/models/invoice_custom_section.rb). String-backed, values as stored.
 */
enum InvoiceCustomSectionType: string
{
    case Manual = 'manual';
    case SystemGenerated = 'system_generated';
}
