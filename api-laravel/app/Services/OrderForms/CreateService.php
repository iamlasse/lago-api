<?php

declare(strict_types=1);

namespace App\Services\OrderForms;

use App\Support\License;
use App\Models\OrderForm;
use App\Models\QuoteVersion;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Services\Validators\ExpirationDate;
use App\Services\QuoteVersions\DealExpiration;

/**
 * Port of Rails' OrderForms::CreateService
 * (app/services/order_forms/create_service.rb) — the signable document over
 * an approved quote version. Called by QuoteVersions::ApproveService and by
 * the GraphQL createOrderForm surface.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?QuoteVersion $quoteVersion,
        private readonly mixed $expiresAt = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('order_form');
        $quoteVersion = $this->quoteVersion;

        if ($quoteVersion === null) {
            return $result->notFoundFailure('quote_version');
        }

        if (! $this->orderFormsEnabled($quoteVersion->organization)) {
            return $result->forbiddenFailure();
        }

        if (! $quoteVersion->isApproved()) {
            return $result->singleValidationFailure('not_approved', 'quote_version');
        }

        if (! $this->validExpiresAt()) {
            return $result->singleValidationFailure('invalid_date', 'expires_at');
        }

        if (! DealExpiration::covers($quoteVersion, $this->expiresAt)) {
            return $result->singleValidationFailure('after_deal_expiration', 'expires_at');
        }

        try {
            DB::transaction(function () use ($quoteVersion, $result): void {
                $orderForm = new OrderForm;
                $orderForm->organization_id = $quoteVersion->organization_id;
                $orderForm->customer_id = $quoteVersion->quote->customer_id;
                $orderForm->quote_version_id = $quoteVersion->id;
                $orderForm->status = 'generated';
                $orderForm->expires_at = $this->expiresAt;
                $orderForm->save();

                // Rails: SendWebhookJob "order_form.created" +
                // Utils::ActivityLog — TODO(port): webhooks / activity logs.

                $result->order_form = $orderForm;
            });
        } catch (QueryException $e) {
            // Rails: rescue ActiveRecord::RecordNotUnique — the unique
            // quote_version_id.
            if ((int) ($e->errorInfo[0] ?? 0) === 23505 || str_contains($e->getMessage(), '23505')) {
                return $result->singleValidationFailure('value_already_exist', 'quote_version_id');
            }

            throw $e;
        }

        return $result;
    }

    protected function validExpiresAt(): bool
    {
        return ExpirationDate::valid($this->expiresAt);
    }

    /** Rails: OrderForms::Premium#order_forms_enabled?. */
    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
