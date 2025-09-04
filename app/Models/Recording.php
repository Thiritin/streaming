<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Recording extends Model
{
    use HasFactory;

    protected $fillable = [
        'show_id',
        'title',
        'slug',
        'description',
        'date',
        'duration',
        'm3u8_url',
        'thumbnail_path',
        'thumbnail_updated_at',
        'thumbnail_capture_error',
        'views',
        'is_published',
    ];

    protected $casts = [
        'date' => 'datetime',
        'duration' => 'integer',
        'views' => 'integer',
        'is_published' => 'boolean',
        'thumbnail_updated_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = ['thumbnail_url', 'formatted_duration'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($recording) {
            if (empty($recording->slug)) {
                $recording->slug = Str::slug($recording->title);
                
                // Ensure slug uniqueness
                $originalSlug = $recording->slug;
                $count = 1;
                while (self::where('slug', $recording->slug)->exists()) {
                    $recording->slug = $originalSlug . '-' . $count;
                    $count++;
                }
            }
        });
    }

    /**
     * Get the show associated with this recording.
     */
    public function show()
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * Get the full URL for the thumbnail.
     * Returns a signed URL for S3 access.
     */
    public function getThumbnailUrlAttribute()
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        // Return a temporary signed URL (valid for 1 hour)
        try {
            return Storage::disk('s3')->temporaryUrl($this->thumbnail_path, now()->addHour());
        } catch (\Exception $e) {
            // Fallback to regular URL if temporary URL fails
            return Storage::disk('s3')->url($this->thumbnail_path);
        }
    }

    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute()
    {
        if (! $this->duration) {
            return null;
        }

        $hours = floor($this->duration / 3600);
        $minutes = floor(($this->duration % 3600) / 60);
        $seconds = $this->duration % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
