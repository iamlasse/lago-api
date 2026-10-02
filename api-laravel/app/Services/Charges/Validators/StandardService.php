<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators;

use App\Services\Validators\DecimalAmount;

/**
 * Port of Rails' Charges::Validators::StandardService.
 */
class StandardService extends BaseService
{
    public function valid(): bool
    {
        $this->validateAmount();

        return parent::valid();
    }

    private function amount(): mixed
    {
        return $this->properties['amount'] ?? null;
    }

    private function validateAmount(): void
    {
        if (! DecimalAmount::validAmount($this->amount())) {
            $this->addError('amount', 'invalid_amount');
        }
    }
}
