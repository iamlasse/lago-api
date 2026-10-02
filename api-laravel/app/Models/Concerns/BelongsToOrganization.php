<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;
use App\Models\Organization;
use App\Support\CurrentContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nearly every Lago table carries `organization_id uuid NOT NULL`. Rails has
 * no global default scope — tenancy is enforced by always querying through
 * `current_organization` (controllers resolve it from the API key / JWT
 * membership). We mirror that exactly:
 *
 *  - the `organization()` relation and `organization_id` assignment are here;
 *  - `scopeOfCurrentOrganization()` is the explicit tenancy filter that
 *    controllers/queries MUST apply (review-enforced, matching Rails);
 *  - `withoutTenancy()` clarity helper for system jobs, which run with no
 *    request context and must query explicitly.
 *
 *  @mixin Model
 */
trait BelongsToOrganization
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function bootBelongsToOrganization(): void
    {
        static::creating(function (Model $model): void {
            if (! $model->isDirty('organization_id') && CurrentContext::$organization !== null) {
                $model->organization_id = CurrentContext::$organization->id;
            }
        });
    }

    #[Scope]
    protected function ofCurrentOrganization(Builder $query): Builder
    {
        $organization = CurrentContext::$organization;

        if ($organization === null) {
            // Same failure mode as Rails: no current_organization means the
            // caller forgot authentication — never silently return data.
            throw new LogicException('No current organization in context; refusing unscoped tenant query.');
        }

        return $query->where($this->qualifyColumn('organization_id'), $organization->id);
    }

    /** Explicit escape hatch for system jobs (clock, billing workers). */
    #[Scope]
    protected function withoutTenancy(Builder $query): Builder
    {
        return $query;
    }
}
