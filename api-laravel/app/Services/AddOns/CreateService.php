<?php

declare(strict_types=1);

namespace App\Services\AddOns;

use App\Models\AddOn;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' AddOns::CreateService
 * (app/services/add_ons/create_service.rb).
 *
 * TODO(port) emission point: SegmentTrackJob "add_on_created" (the Segment
 * analytics job family is a later milestone) and the activity_loggable /
 * api-log middleware the non-GET writes share.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly array $args,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('add_on');
        $args = $this->args;

        $addOn = new AddOn([
            'organization_id' => $args['organization_id'] ?? null,
            'name' => $args['name'] ?? null,
            'invoice_display_name' => $args['invoice_display_name'] ?? null,
            'code' => $args['code'] ?? null,
            'description' => $args['description'] ?? null,
            'amount_cents' => $args['amount_cents'] ?? null,
            'amount_currency' => $args['amount_currency'] ?? null,
        ]);

        return $this->rescueFailures(function () use ($result, $addOn): BaseResult {
            DB::transaction(function () use ($result, $addOn): void {
                $errors = $addOn->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $addOn->save();

                // Rails: ApplyTaxesService runs only when the input carried a
                // tax_codes key (nil is not a valid list, absent is fine).
                if (array_key_exists('tax_codes', $this->args)) {
                    ApplyTaxesService::call(
                        addOn: $addOn,
                        taxCodes: is_array($this->args['tax_codes']) ? $this->args['tax_codes'] : [],
                    )->raiseIfError();
                }
            });

            // TODO(port): SegmentTrackJob.perform_later("add_on_created").

            $result->add_on = $addOn;

            return $result;
        }, $result);
    }
}
