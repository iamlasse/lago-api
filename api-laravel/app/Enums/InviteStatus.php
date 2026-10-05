<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * invites.status — integer column, Rails enum order is the stored value,
 * 0-based (pending=0, accepted=1, revoked=2). Never renumber.
 */
enum InviteStatus: int
{
    case Pending = 0;
    case Accepted = 1;
    case Revoked = 2;

    /** The Rails enum name (the string the API emits for the value). */
    public function label(): string
    {
        return \Illuminate\Support\Str::snake($this->name);
    }
}
