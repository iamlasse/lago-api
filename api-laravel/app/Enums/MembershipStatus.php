<?php

namespace App\Enums;

/**
 * memberships.status — integer column, Rails enum order is the stored
 * value, 0-based (active=0, revoked=1). Never renumber.
 */
enum MembershipStatus: int
{
    case Active = 0;
    case Revoked = 1;

    /** The Rails enum name (the string the API emits for the value). */
    public function label(): string
    {
        return strtolower($this->name);
    }
}
