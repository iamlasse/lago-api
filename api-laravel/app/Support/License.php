<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Port of LagoUtils::License (lib/lago_utils/license.rb) for the emission
 * gates. Rails verifies the LAGO_LICENSE token against LAGO_LICENSE_URL and
 * caches the premium answer; the port follows the same convention the
 * api-permissions middleware already established (see
 * App\Http\Middleware\AuthenticateApiKey::apiPermissionsEnabled): a
 * configured LAGO_LICENSE token is treated as premium, no token means
 * non-premium. TODO(port): the license HTTP verification.
 */
final class License
{
    /** Rails: `License.premium?`. */
    public static function premium(): bool
    {
        return config('lago.license') !== null;
    }
}
