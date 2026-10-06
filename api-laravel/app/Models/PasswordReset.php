<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `password_resets` (Rails' PasswordReset).
 * Added by the password-resets GraphQL slice (the model did not exist yet;
 * report: new file, no existing model touched).
 */
#[Fillable([
    'user_id',
    'token',
    'expire_at',
])]
#[Table(name: 'password_resets')]
class PasswordReset extends BaseModel
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rails: PasswordReset.where("expire_at > ?", Time.current) — the scope the resolver and the reset service share. */
    public function scopeActive($query)
    {
        return $query->where('expire_at', '>', now());
    }

    public function isExpired(): bool
    {
        /** @var CarbonInterface|null $expireAt */
        $expireAt = $this->expire_at;

        return $expireAt === null || $expireAt->isPast();
    }

    protected function casts(): array
    {
        return [
            'expire_at' => 'datetime',
        ];
    }
}
