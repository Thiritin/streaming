<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One viewer following one show. See the create migration.
 */
class ShowSubscription extends Model
{
    protected $fillable = ['user_id', 'show_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }
}
