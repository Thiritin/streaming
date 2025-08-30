<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Emote extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'file_path',
        's3_key',
        'url',
        'uploaded_by_user_id',
        'is_approved',
        'approved_by_user_id',
        'approved_at',
        'is_global',
        'usage_count',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'is_global' => 'boolean',
        'approved_at' => 'datetime',
        'usage_count' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($emote) {
            // Ensure emote name is lowercase and alphanumeric with underscores
            $emote->name = Str::lower(preg_replace('/[^a-zA-Z0-9_]/', '', $emote->name));
        });

        static::deleting(function ($emote) {
            // Delete from S3 when emote is deleted
            if ($emote->s3_key && Storage::disk('s3')->exists($emote->s3_key)) {
                Storage::disk('s3')->delete($emote->s3_key);
            }
        });
    }

    /**
     * Get the user who uploaded the emote.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Get the user who approved the emote.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Get users who have favorited this emote.
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_emote_favorites')
            ->withTimestamps();
    }

    /**
     * Scope for approved emotes.
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope for pending approval emotes.
     */
    public function scopePending($query)
    {
        return $query->where('is_approved', false);
    }

    /**
     * Scope for global emotes.
     */
    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }

    /**
     * Scope for personal emotes.
     */
    public function scopePersonal($query)
    {
        return $query->where('is_global', false);
    }

    /**
     * Scope for emotes available to a user.
     */
    public function scopeAvailableFor($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('is_global', true)
                ->orWhere('uploaded_by_user_id', $user->id);
        })->approved();
    }

    /**
     * Approve the emote.
     */
    public function approve(User $approver)
    {
        $this->update([
            'is_approved' => true,
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Reject the emote.
     */
    public function reject()
    {
        // Delete the emote and its S3 file
        $this->delete();
    }

    /**
     * Increment usage count.
     */
    public function incrementUsage()
    {
        $this->increment('usage_count');
    }

    /**
     * Get the full URL for the emote.
     */
    public function getUrlAttribute($value)
    {
        if ($value) {
            return $value;
        }

        if ($this->s3_key) {
            return Storage::disk('s3')->url($this->s3_key);
        }

        return null;
    }

    /**
     * Check if user can use this emote.
     */
    public function canBeUsedBy(User $user): bool
    {
        if (! $this->is_approved) {
            return false;
        }

        if ($this->is_global) {
            return true;
        }

        return $this->uploaded_by_user_id === $user->id;
    }

    /**
     * Get emote code for chat.
     */
    public function getCodeAttribute(): string
    {
        return ':'.$this->name.':';
    }
}
