<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator;

/**
 * Port of Rails' Integrations::Aggregator::AccountInformationService's
 * AccountInformation data define — a single field, the account id.
 */
final class AccountInformation
{
    public function __construct(
        public readonly ?string $id,
    ) {}
}
