<?php

namespace App\Models;

use Illuminate\Support\Facades\Hash;

/**
 * Port of Rails' User (frozen `users` table): bcrypt `password_digest` via
 * has_secure_password, minimal columns. Password hashing is cross-language
 * compatible: PHP's password_verify accepts Ruby bcrypt's $2a$/$2b$ prefixes.
 */
class User extends BaseModel
{
    protected $fillable = ['email', 'password', 'cs_admin'];

    protected $hidden = ['password_digest'];

    protected $casts = [
        'cs_admin' => 'boolean',
    ];

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMemberships()
    {
        return $this->hasMany(Membership::class)->where('status', Membership::STATUS_ACTIVE);
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'memberships', 'user_id', 'organization_id');
    }

    // -- has_secure_password port ------------------------------------------

    public function setPasswordAttribute(?string $password): void
    {
        if ($password !== null) {
            $this->attributes['password_digest'] = password_hash($password, PASSWORD_BCRYPT);
        }
    }

    public function authenticate(string $password): bool
    {
        $digest = $this->password_digest ?? '';

        return $digest !== '' && password_verify($password, $digest);
    }
}
