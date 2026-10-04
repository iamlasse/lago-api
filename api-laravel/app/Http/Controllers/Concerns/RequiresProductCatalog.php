<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use App\Exceptions\Api\ForbiddenException;
use App\Exceptions\Api\InvalidRequestException;

/**
 * Port of Rails' Api::RequiresProductCatalog concern
 * (app/controllers/concerns/api/requires_product_catalog.rb) — guards the
 * v2 catalog endpoints: requires the organization's product_catalog feature
 * flag. Applied ahead of any other work in every action, so a disabled
 * catalog is a 403 before any pagination parameter or lookup is read.
 *
 * Also carries the shared V2 base controller envelope helpers
 * (Api::V2::BaseController): every native v2 400 is keyed by the offending
 * parameter.
 */
trait RequiresProductCatalog
{
    /**
     * Rails: `ensure_product_catalog!` (before_action on every action).
     */
    protected function ensureProductCatalog(): void
    {
        $organization = $this->currentOrganization();

        $flags = $organization->feature_flags ?? [];

        if (! in_array('product_catalog', (array) $flags, true)) {
            throw new ForbiddenException('feature_unavailable');
        }
    }

    /**
     * Rails: `missing_parameter_error` rescue —
     * invalid_request_error(code: "missing_parameter",
     * error_details: {param => {reason: "missing"}}).
     */
    protected function missingParameter(string $param): never
    {
        throw new InvalidRequestException('missing_parameter', [$param => ['reason' => 'missing']]);
    }

    /**
     * Rails: `params.require(:product).permit(...)` with the V2 envelope —
     * a missing wrapper is a keyed 400, not the V1 parameter_missing shape.
     */
    protected function requireParams(Request $request, string $key): array
    {
        $value = $request->input($key);

        if ($value === null || $value === '' || ! is_array($value)) {
            $this->missingParameter($key);
        }

        return $value;
    }
}
