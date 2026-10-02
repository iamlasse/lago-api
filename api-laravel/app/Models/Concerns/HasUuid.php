<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * Every Lago table uses `id uuid DEFAULT gen_random_uuid()` (v4 random UUIDs).
 * HasUuids orders UUIDs; Rails uses SecureRandom.uuid (v4) — keep v4 so
 * fixtures and contract goldens match byte-for-byte.
 */
trait HasUuid
{
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    protected static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            if ($model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }

    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }

    public function newUniqueId(): ?string
    {
        return (string) Str::uuid();
    }
}
