<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Binds the frozen SDL's `User` type to the ported field resolvers in
 * UserType (Lighthouse discovers type classes by the type's name, so this
 * subclass carries `memberships` / `organizations` / `premium` onto the
 * type without directives).
 */
class User extends UserType {}
