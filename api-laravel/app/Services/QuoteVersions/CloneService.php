<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions;

use App\Support\License;
use App\Models\QuoteVersion;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Quotes\LockService;
use Illuminate\Database\QueryException;
use App\Services\Failures\LockAcquisitionFailure;

/**
 * Port of Rails' QuoteVersions::CloneService
 * (app/services/quote_versions/clone_service.rb) — starts a fresh draft off
 * an existing version, voiding the active draft (reason: superseded) to free
 * the partial unique index slot.
 */
class CloneService extends BaseService
{
    public function __construct(
        private readonly ?QuoteVersion $quoteVersion,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('quote_version');
        $quoteVersion = $this->quoteVersion;

        if ($quoteVersion === null) {
            return $result->notFoundFailure('quote_version');
        }

        if (! $this->orderFormsEnabled($quoteVersion->organization)) {
            return $result->forbiddenFailure();
        }

        try {
            DB::transaction(function () use ($quoteVersion, $result): void {
                LockService::call($quoteVersion->quote, function () use ($quoteVersion, $result): void {
                    $quoteVersion->refresh();

                    if (! $this->clonable($quoteVersion)) {
                        $result->singleValidationFailure('not_clonable', 'status');

                        return;
                    }

                    $this->voidActiveVersion($quoteVersion);

                    $result->quote_version = $this->createNextVersion($quoteVersion);

                    // Rails: Utils::ActivityLog.produce_after_commit(
                    //   result.quote_version, "quote.version_created").
                    // TODO(port): activity logs (ClickHouse slice).
                });
            });
        } catch (LockAcquisitionFailure) {
            return $result->singleValidationFailure('concurrency_conflict', 'base');
        } catch (CloneError $e) {
            return $result->serviceFailure('clone_failed', $e->getMessage(), $e);
        } catch (QueryException $e) {
            // Rails: rescue ActiveRecord::RecordNotUnique — a draft survived
            // the void (it can only be the one the void itself refused).
            if ((int) ($e->errorInfo[0] ?? 0) === 23505 || str_contains($e->getMessage(), '23505')) {
                return $result->singleValidationFailure('active_version_exists', 'status');
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Rails: clonable? — cloning coexists with nothing approved: the approval
     * is the signature being collected.
     */
    protected function clonable(QuoteVersion $quoteVersion): bool
    {
        return ! $quoteVersion->quote
            ->quoteVersions()
            ->where('status', 'approved')
            ->exists();
    }

    /**
     * The mention variables are the snapshot taken when a version was
     * approved, and the clone has not been approved. Carrying them over would
     * also break what the read path assumes, that a filled column means an
     * approved version.
     */
    protected function createNextVersion(QuoteVersion $quoteVersion): QuoteVersion
    {
        $cloned = $quoteVersion->replicate();

        $cloned->status = 'draft';
        $cloned->sequential_id = null;
        $cloned->void_reason = null;
        $cloned->voided_at = null;
        $cloned->approved_at = null;
        $cloned->mention_variables = null;

        $cloned->save();

        return $cloned;
    }

    /**
     * At most one draft exists per quote. If the version being cloned is that
     * draft, void it directly; only look it up when cloning a non-draft (e.g.
     * an older voided version while a newer draft is the active one).
     */
    protected function voidActiveVersion(QuoteVersion $quoteVersion): void
    {
        $activeDraft = $quoteVersion->isDraft()
            ? $quoteVersion
            : $quoteVersion->quote->quoteVersions()->where('status', 'draft')->first();

        if ($activeDraft === null) {
            return;
        }

        $voidResult = VoidService::call(
            quoteVersion: $activeDraft,
            reason: 'superseded',
        );

        if ($voidResult->failure()) {
            throw CloneError::fromResult($voidResult);
        }
    }

    /** Rails: OrderForms::Premium#order_forms_enabled?. */
    protected function orderFormsEnabled(object $organization): bool
    {
        return License::premium()
            && in_array('order_forms', (array) ($organization->feature_flags ?? []), true);
    }
}
