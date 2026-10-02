<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Every Lago table uses `id uuid DEFAULT gen_random_uuid()` (v4 random UUIDs).
 * HasUuids orders UUIDs; Rails uses SecureRandom.uuid (v4) — keep v4 so
 * fixtures and contract goldens match byte-for-byte.
 *
 * @mixin Model
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

    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }

    public function newUniqueId(): ?string
    {
        return (string) Str::uuid();
    }

    protected static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            if ($model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
