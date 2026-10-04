<?php

declare(strict_types=1);

namespace App\Services\AddOns;

use App\Models\AddOn;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' AddOns::UpdateService
 * (app/services/add_ons/update_service.rb) — only the keys present in the
 * params are assigned, and the tax sync runs only when a tax_codes key was
 * carried.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?AddOn $addOn,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('add_on');

        if ($this->addOn === null) {
            return $result->notFoundFailure('add_on');
        }

        $addOn = $this->addOn;
        $params = $this->params;

        if (array_key_exists('name', $params)) {
            $addOn->name = $params['name'];
        }

        if (array_key_exists('invoice_display_name', $params)) {
            $addOn->invoice_display_name = $params['invoice_display_name'];
        }

        if (array_key_exists('description', $params)) {
            $addOn->description = $params['description'];
        }

        if (array_key_exists('code', $params)) {
            $addOn->code = $params['code'];
        }

        if (array_key_exists('amount_cents', $params)) {
            $addOn->amount_cents = $params['amount_cents'];
        }

        if (array_key_exists('amount_currency', $params)) {
            $addOn->amount_currency = $params['amount_currency'];
        }

        return $this->rescueFailures(function () use ($result, $addOn): BaseResult {
            DB::transaction(function () use ($result, $addOn): void {
                $errors = $addOn->validateAttributes();

                if ($errors !== []) {
                    // Rails: the save! raises RecordInvalid inside the
                    // transaction, rolling it back.
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $addOn->save();

                if (array_key_exists('tax_codes', $this->params)) {
                    ApplyTaxesService::call(
                        addOn: $addOn,
                        taxCodes: is_array($this->params['tax_codes']) ? $this->params['tax_codes'] : [],
                    )->raiseIfError();
                }
            });

            $result->add_on = $addOn;

            return $result;
        }, $result);
    }
}
