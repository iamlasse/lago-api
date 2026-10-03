<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Services\Validators\ExpirationDate;

/**
 * Port of Rails' Coupons::ValidateService
 * (app/services/coupons/validate_service.rb).
 */
class ValidateService extends BaseValidator
{
    public function valid(): bool
    {
        $this->validExpirationAt();

        if ($this->errors()) {
            $this->result->validationFailure($this->messages());

            return false;
        }

        return true;
    }

    private function validExpirationAt(): bool
    {
        if (ExpirationDate::valid($this->arg('expiration_at'))) {
            return true;
        }

        return $this->addError('expiration_at', 'invalid_date');
    }
}
