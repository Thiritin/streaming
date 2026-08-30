<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account as one provider knows it.
 *
 * The provider owns this row and nothing else: `email`, `name` and `avatar` are what
 * it last released, and they are deliberately not written back onto `users`. With
 * several providers that column would be two of them fighting over one value.
 */
class UserIdentity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'auth_provider_id',
        'subject',
        'email',
        'name',
        'avatar',
        'granted_role_ids',
        'last_login_at',
    ];

    protected $casts = [
        'granted_role_ids' => 'array',
        'last_login_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AuthProvider::class, 'auth_provider_id');
    }
}
