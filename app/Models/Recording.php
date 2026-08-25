<?php

namespace App\Models;

use App\Support\SkipSegments;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Recording extends Model
{
    use HasFactory;

    protected $fillable = [
        'show_id',
        'source_id',
        'event_id',
        'category_id',
        'title',
        'slug',
        'description',
        'date',
        'starts_at',
        'ends_at',
        'archive_prefix',
        'status',
        'duration',
        'm3u8_url',
        'skip_segments',
        'thumbnail_path',
        'thumbnail_updated_at',
        'thumbnail_capture_error',
        'views',
        'is_published',
        'required_roles',
    ];

    protected $casts = [
        'date' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'playlist_built_at' => 'datetime',
        'duration' => 'integer',
        'segment_count' => 'integer',
        'archive_bytes' => 'integer',
        'views' => 'integer',
        'is_published' => 'boolean',
        'skip_segments' => 'array',
        'thumbnail_updated_at' => 'datetime',
        'required_roles' => 'array',
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
                    $recording->slug = $originalSlug.'-'.$count;
                    $count++;
                }
            }

            /*
             * A cut inherits its show's run. Without a show - an edit imported straight
             * into the archive - the date decides. Create only, so clearing the field
             * later sticks; see Show::boot for the same reasoning.
             */
            if ($recording->event_id === null && $recording->show_id === null) {
                $recording->event_id = Event::forDate(
                    $recording->date ? Carbon::parse($recording->date) : null,
                )?->id;
            }
        });
    }

    /**
     * Cut markers are normalised to the app timezone before they are stored.
     *
     * starts_at and ends_at are `timestamp without time zone`, matching the rest of the
     * schema, and Laravel writes them with `Y-m-d H:i:s` and no offset. The digits that
     * reach Postgres are therefore whatever timezone the Carbon happened to be in, and a
     * UTC one lands as local time on read: the instant silently moves by the offset.
     *
     * It only shows up later, as a cut that resolves to zero segments and reports the
     * archive as expired, so normalise here rather than expecting every caller to
     * remember. Callers passing app-local values are unaffected.
     */
    protected function startsAt(): Attribute
    {
        return $this->localDateTime();
    }

    protected function endsAt(): Attribute
    {
        return $this->localDateTime();
    }

    protected function localDateTime(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null
                ? null
                : Carbon::parse($value)->setTimezone(config('app.timezone')),
        );
    }

    /**
     * Get the show associated with this recording.
     */
    public function show()
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * The source whose archive this recording is a view of.
     */
    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * The run this cut is filed under directly. Usually null: the show's is what
     * applies, and this column only exists to override it or to file an edit that
     * was imported without a show.
     */
    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * What viewers said under it. One level deep; see RecordingComment.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(RecordingComment::class);
    }

    /**
     * The run that applies: this recording's own, else its show's.
     */
    public function effectiveEvent(): ?Event
    {
        return $this->event ?? $this->show?->event;
    }

    /**
     * The category set on this recording directly. Usually null: the show's is
     * what applies, and this column only exists to override it or to categorise
     * an edit that was imported without a show.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The category that applies: this recording's own, else its show's.
     */
    public function effectiveCategory(): ?Category
    {
        return $this->category ?? $this->show?->category;
    }

    /**
     * Recordings in a category, counting the ones that only have it through their
     * show. Written as one query rather than a filter in PHP, because the archive
     * grid pages over the result.
     */
    public function scopeInCategory($query, string $slug)
    {
        return $query->where(function ($query) use ($slug) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $slug))
                ->orWhere(function ($query) use ($slug) {
                    $query->whereNull('category_id')
                        ->whereHas('show.category', fn ($q) => $q->where('slug', $slug));
                });
        });
    }

    /**
     * Recordings filed under a run, counting the ones that only have it through
     * their show. One query, for the same reason as inCategory: the archive grid
     * pages over the result. Takes an id or a slug, whichever the caller carries.
     */
    public function scopeInEvent($query, string $event)
    {
        $column = Event::keyColumn($event);

        return $query->where(function ($query) use ($event, $column) {
            $query->whereHas('event', fn ($q) => $q->where($column, $event))
                ->orWhere(function ($query) use ($event, $column) {
                    $query->whereNull('event_id')
                        ->whereHas('show.event', fn ($q) => $q->where($column, $event));
                });
        });
    }

    /**
     * Everything not filed under a run - the complement of inEvent, written out
     * rather than negated, because a NOT around a whereHas does not answer for the
     * rows that have no related row at all.
     */
    public function scopeNotInEvent($query, string $event)
    {
        $column = Event::keyColumn($event);

        return $query
            ->whereDoesntHave('event', fn ($q) => $q->where($column, $event))
            ->where(function ($query) use ($event, $column) {
                // A recording with its own event has already been cleared above; only
                // one without inherits, so only one without can be excluded by its show.
                $query->whereNotNull('event_id')
                    ->orWhereDoesntHave('show.event', fn ($q) => $q->where($column, $event));
            });
    }

    /**
     * Recordings filed under no run at all - neither their own nor their show's.
     * These are what the year chips still cover, so nothing falls off the archive
     * because the calendar was set up after it was published.
     */
    public function scopeWithoutEvent($query)
    {
        return $query->whereNull('event_id')
            ->where(function ($query) {
                $query->whereNull('show_id')
                    ->orWhereHas('show', fn ($q) => $q->whereNull('event_id'));
            });
    }

    /**
     * Slug of the archive this cut reads from.
     *
     * Prefers the stored prefix so an existing recording keeps resolving if its source is
     * later renamed, and falls back to the live relation for recordings created before
     * the prefix was recorded.
     */
    public function archiveSourceSlug(): ?string
    {
        if ($this->archive_prefix) {
            return str_contains($this->archive_prefix, '/')
                ? substr(strrchr($this->archive_prefix, '/'), 1)
                : $this->archive_prefix;
        }

        return $this->source?->slug ?? $this->show?->source?->slug;
    }

    /**
     * Whether the cut is fully specified. An end marker is required: the archive is a
     * continuous timeline with no natural end for a source that stays online for the
     * whole event, so something has to say where the recording stops.
     */
    public function hasCut(): bool
    {
        return $this->starts_at !== null && $this->ends_at !== null;
    }

    /**
     * The skippable stretches, sorted and non-overlapping. Read through here rather
     * than off the column: rows written before the column existed hold null, and a
     * re-cut can leave a range hanging past the new end.
     *
     * @return array<int, array{start: int, end: int, label: string|null}>
     */
    /**
     * What the skip points were marked against.
     *
     * The cut is what gives a skip its meaning: the same "3365" is a different
     * moment once the in-point moves. A form carries this from the load and hands
     * it back on save, so a save built against a cut somebody else has since
     * changed is refused rather than written on top.
     */
    public function cutFingerprint(): string
    {
        return md5(implode('|', [
            $this->starts_at?->toIso8601String() ?? '',
            $this->ends_at?->toIso8601String() ?? '',
            (int) $this->duration,
        ]));
    }

    public function skips(): array
    {
        return SkipSegments::normalise($this->skip_segments, $this->duration);
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

    /**
     * Check if recording has access restrictions.
     */
    public function hasAccessRestriction(): bool
    {
        return ! empty($this->required_roles);
    }

    /**
     * Check if a user can access this recording.
     */
    public function canBeAccessedBy(?User $user): bool
    {
        // No restrictions = everyone can access
        if (! $this->hasAccessRestriction()) {
            return true;
        }

        // Must be logged in to access restricted content
        if (! $user) {
            return false;
        }

        // Check if user has any of the required roles
        return $user->hasAnyRole($this->required_roles);
    }

    /**
     * Scope for recordings accessible by a user.
     */
    public function scopeAccessibleBy($query, ?User $user)
    {
        return $query->where(function ($q) use ($user) {
            // No restrictions (null or empty array)
            $q->whereNull('required_roles')
                ->orWhereJsonLength('required_roles', 0);

            // Or user has one of the required roles
            if ($user) {
                $userRoles = $user->roles()->pluck('slug')->toArray();
                foreach ($userRoles as $role) {
                    $q->orWhereJsonContains('required_roles', $role);
                }
            }
        });
    }
}
