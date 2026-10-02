<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Port of Rails' User (frozen `users` table): bcrypt `password_digest` via
 * has_secure_password, minimal columns. Password hashing is cross-language
 * compatible: PHP's password_verify accepts Ruby bcrypt's $2a$/$2b$ prefixes.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable(['email', 'password', 'cs_admin'])]
#[\Illuminate\Database\Eloquent\Attributes\Hidden(['password_digest'])]
class User extends BaseModel
{
    use HasFactory;

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->hasMany(Membership::class)->where('status', MembershipStatus::Active->value);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships', 'user_id', 'organization_id');
    }

    protected function password(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(set: function (?string $password) {
            if ($password !== null) {
                $this->attributes['password_digest'] = password_hash($password, PASSWORD_BCRYPT);
            }
            return ['password_digest' => password_hash($password, PASSWORD_BCRYPT)];
        });
    }

    public function authenticate(string $password): bool
    {
        $digest = $this->password_digest ?? '';

        return $digest !== '' && password_verify($password, $digest);
    }
    protected function casts(): array
    {
        return [
            'cs_admin' => 'boolean',
        ];
    }
}
