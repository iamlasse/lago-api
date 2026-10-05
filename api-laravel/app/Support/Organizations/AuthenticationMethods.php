<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use App\Support\License;
use App\Models\Organization;

/**
 * Port of Rails' Organizations::AuthenticationMethods concern
 * (app/models/concerns/organizations/authentication_methods.rb).
 *
 * The concern defines `<method>_authentication_enabled?` /
 * `enable_<method>_authentication!` on the Organization model; the port keeps
 * the model untouched and expresses the checks statically here (the login
 * services read them). The `enable_...!` mutators belong to the organization
 * settings / integrations slices.
 *
 * NOTE (same as Rails): premium methods share their name with the premium
 * integration flag — enabling okta/entra_id authentication additionally
 * requires License.premium? and the organization's premium_integrations.
 */
final class AuthenticationMethods
{
    public const EMAIL_PASSWORD = 'email_password';

    public const GOOGLE_OAUTH = 'google_oauth';

    public const OKTA = 'okta';

    public const ENTRA_ID = 'entra_id';

    public const FREE = [self::EMAIL_PASSWORD, self::GOOGLE_OAUTH];

    public const PREMIUM = [self::OKTA, self::ENTRA_ID];

    public const ALL = [self::EMAIL_PASSWORD, self::GOOGLE_OAUTH, self::OKTA, self::ENTRA_ID];

    /**
     * Rails: `<method>_authentication_enabled?` — free methods check the
     * authentication_methods array; premium methods additionally require the
     * premium flag and the premium_integrations entry.
     */
    public static function enabled(Organization $organization, string $method): bool
    {
        $methods = (array) ($organization->authentication_methods ?? []);

        if (! in_array($method, $methods, true)) {
            return false;
        }

        if (in_array($method, self::FREE, true)) {
            return true;
        }

        // NOTE: Authentication methods with the same name as the premium
        // integration.
        return License::premium()
            && in_array($method, (array) ($organization->premium_integrations ?? []), true);
    }
}
