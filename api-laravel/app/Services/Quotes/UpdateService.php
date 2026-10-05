<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Support\License;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Quotes::UpdateService (app/services/quotes/update_service.rb)
 * — quotes carry no editable columns of their own, so the only update is the
 * owners sync (Rails notes the quote.updated webhook as a TODO upstream too).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?Quote $quote,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('quote');
        $quote = $this->quote;

        if ($quote === null) {
            return $result->notFoundFailure('quote');
        }

        if (! $this->orderFormsEnabled($quote->organization)) {
            return $result->forbiddenFailure();
        }

        $owners = $this->normalizeOwners($this->params['owners'] ?? null);

        if (! $this->validOwners($quote, $owners)) {
            return $result->singleValidationFailure('invalid', 'owners');
        }

        if (array_key_exists('owners', $this->params)) {
            $this->syncOwners($quote, $owners);
        }

        // Rails: TODO SendWebhookJob "quote.updated" — the port carries the
        // same gap.

        $result->quote = $quote->refresh();

        return $result;
    }

    // -- Helpers ------------------------------------------------------------------

    /** Rails: valid_owners? — every owner must be an active membership user. */
    protected function validOwners(Quote $quote, array $owners): bool
    {
        if ($owners === []) {
            return true;
        }

        $known = $quote->organization
            ->memberships()
            ->active()
            ->whereIn('user_id', $owners)
            ->pluck('user_id')
            ->map(strval(...))
            ->all();

        return array_diff($owners, $known) === [];
    }

    /**
     * Rails: normalize_owners — scalar or list, always a flat list of unique
     * strings.
     *
     * @return list<string>
     */
    protected function normalizeOwners(mixed $owners): array
    {
        if ($owners === null || $owners === '' || $owners === []) {
            return [];
        }

        if (is_array($owners)) {
            return array_values(array_unique(array_map(strval(...), $owners)));
        }

        return [(string) $owners];
    }

    protected function syncOwners(Quote $quote, array $owners): void
    {
        DB::transaction(function () use ($quote, $owners): void {
            $currentOwners = $quote->owners()->pluck('users.id')->map(strval(...))->all();

            $ownersToRemove = array_diff($currentOwners, $owners);

            if ($ownersToRemove !== []) {
                $quote->quoteOwners()->whereIn('user_id', $ownersToRemove)->delete();
            }

            foreach (array_diff($owners, $currentOwners) as $userId) {
                $quote->quoteOwners()->create([
                    'organization_id' => $quote->organization_id,
                    'user_id' => $userId,
                ]);
            }
        });
    }

    /** Rails: OrderForms::Premium#order_forms_enabled?. */
    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
