<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' QuoteOwner (app/models/quote_owner.rb) — the quote ← user
 * ownership join the quotes/order-forms index filters (owner_id) resolve
 * through. No uuid PK: the frozen schema keeps a bigint identity column.
 */
#[Fillable([
    'organization_id',
    'quote_id',
    'user_id',
])]
#[Table(name: 'quote_owners')]
class QuoteOwner extends BaseModel
{
    use HasFactory;

    /**
     * The join table keeps a bigint identity PK (the one table in the quote
     * aggregate without a uuid key), so the BaseModel's uuid generation is
     * switched off.
     */
    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
    }

    public function uniqueIds(): array
    {
        return [];
    }

    /** Rails: `belongs_to :organization`. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `belongs_to :quote`. */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** Rails: `belongs_to :user`. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function bootHasUuid(): void {}
}
