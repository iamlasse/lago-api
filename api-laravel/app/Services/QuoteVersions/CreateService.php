<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions;

use App\Models\Quote;
use App\Support\License;
use App\Models\QuoteVersion;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Services\QuoteVersions\Validators\Validators;

/**
 * Port of Rails' QuoteVersions::CreateService
 * (app/services/quote_versions/create_service.rb) — the first version of a
 * quote, initialized by Quotes::CreateService with the deal currency.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Quote $quote,
        private readonly array $params = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('quote_version');
        $quote = $this->quote;

        if ($quote === null) {
            return $result->notFoundFailure('quote');
        }

        if (! $this->orderFormsEnabled($quote->organization)) {
            return $result->forbiddenFailure();
        }

        if ($this->activeVersionExists($quote)) {
            return $result->forbiddenFailure('active_version_exists');
        }

        $versionParams = array_intersect_key($this->params, array_flip([
            'billing_items', 'content', 'currency', 'billing_entity_id',
        ]));

        $quoteVersion = new QuoteVersion;
        $quoteVersion->organization_id = $quote->organization_id;
        $quoteVersion->quote_id = $quote->id;

        foreach ($versionParams as $key => $value) {
            $quoteVersion->{$key} = $value;
        }

        $validator = Validators::for($result, $quoteVersion, 'update');

        if ($validator !== null && ! $validator->valid()) {
            return $result;
        }

        try {
            DB::transaction(function () use ($quoteVersion, $result): void {
                $quoteVersion->save();

                $result->quote_version = $quoteVersion;
            });
        } catch (QueryException $e) {
            // Rails: rescue ActiveRecord::RecordNotUnique — the partial unique
            // index on the active version.
            if ((int) ($e->errorInfo[0] ?? 0) === 23505 || str_contains($e->getMessage(), '23505')) {
                return $result->forbiddenFailure('active_version_exists');
            }

            throw $e;
        }

        return $result;
    }

    /** Rails: active_version_exists? — draft or approved. */
    protected function activeVersionExists(Quote $quote): bool
    {
        return $quote->quoteVersions()
            ->whereIn('status', ['draft', 'approved'])
            ->exists();
    }

    /** Rails: OrderForms::Premium#order_forms_enabled?. */
    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
