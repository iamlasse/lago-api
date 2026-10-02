<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for all Lago models. The Postgres schema is frozen (Rails'
 * db/structure.sql): uuid v4 primary keys, `timestamp(6) without time zone`
 * columns holding UTC, soft deletes on `deleted_at`.
 */
#[\Illuminate\Database\Eloquent\Attributes\DateFormat('Y-m-d H:i:s.u')]
abstract class BaseModel extends Model
{
    use HasUuid;

    /**
     * Rails model names, verbatim — the `versions` audit table stores Rails
     * class names in item_type and api_logs/webhooks expose them.
     */
    public function railsName(): string
    {
        return basename(str_replace('\\', '/', static::class));
    }
}
