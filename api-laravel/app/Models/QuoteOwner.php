<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Minimal read-model scaffold of Rails' QuoteOwner
 * (app/models/quote_owner.rb) — the quote ← user ownership join the orders
 * index filter (`owner_id`) resolves through. No uuid PK: the frozen schema
 * keeps a bigint identity column.
 *
 * TODO(port): the full quotes slice owns this model.
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
}
