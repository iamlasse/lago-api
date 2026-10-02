<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Binds the frozen SDL's `Membership` type to the ported field resolvers in
 * MembershipType (`status` serializes the Rails enum name, not the raw
 * integer column).
 *
 * `permissions: Permissions!` and `roles: [String!]!` need the Permission /
 * roles port and currently resolve to null through the default field
 * resolver (a null violation when selected) — see FULL_SCHEMA_NOTES.md.
 */
class Membership extends MembershipType {}
