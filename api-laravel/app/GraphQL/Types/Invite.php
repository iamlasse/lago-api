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
        // A freshly-created row may not carry the enum in memory yet —
        // read the stored integer position (Rails enum order, pending = 0).
        $status = $root->status;

        if ($status instanceof \App\Enums\InviteStatus) {
            return $status->label();
        }

        return \App\Enums\InviteStatus::from((int) ($root->getRawOriginal('status') ?? 0))->label();
    }
}
