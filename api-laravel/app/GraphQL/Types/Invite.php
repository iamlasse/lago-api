<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Invite as InviteModel;

/**
 * Field resolvers for the frozen SDL's `Invite` type (port of Rails'
 * Types::Invites::Object) — `status` serializes the Rails enum name, not
 * the raw integer column.
 */
class Invite
{
    public function status(InviteModel $root): string
    {
        return $root->status->label();
    }
}
