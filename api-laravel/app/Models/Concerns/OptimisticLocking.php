<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Exceptions\StaleObjectError;

/**
 * Port of Rails' `lock_optimistically` (ActiveRecord) for the frozen
 * schema's `lock_version` column (wallets, wallet_transactions).
 *
 * Rails bumps lock_version on every UPDATE and adds
 * `WHERE lock_version = <loaded version>` to the statement; zero affected
 * rows raise ActiveRecord::StaleObjectError, which callers retry
 * (WalletTransactions::CreateFromParamsService — 5 attempts;
 * Customers::RefreshWalletJob — 6 polynomial retries). This concern ports
 * exactly that: every UPDATE of a model carrying a non-null loaded
 * lock_version is version-guarded, bumps the column and throws
 * StaleObjectError when the row moved underneath us.
 */
trait OptimisticLocking
{
    /**
     * Rails: `self.lock_optimistically = false` — per-model opt-out.
     */
    public bool $optimisticLocking = true;

    protected function performUpdate(Builder $query): bool
    {
        // If the updating event returns false, we cancel the update operation.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $originalVersion = $this->getOriginal('lock_version');
        $versioned = $this->optimisticLocking && $originalVersion !== null;

        if ($versioned && ! $this->isDirty('lock_version')) {
            $this->setAttribute('lock_version', ((int) $originalVersion) + 1);
        }

        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            if ($versioned) {
                $query->where($this->qualifyColumn('lock_version'), $originalVersion);
            }

            $affected = $this->setKeysForSaveQuery($query)->update($dirty);

            if ($versioned && $affected === 0) {
                throw new StaleObjectError($this->railsName());
            }

            $this->refreshSavedAttributes();

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }
}
