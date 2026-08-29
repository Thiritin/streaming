<?php

namespace App\Models;

use App\Services\ThumbnailService;
use App\Support\Markdown;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Show extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'source_id',
        'event_id',
        'category_id',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'status',
        'archived_at',
        'cancellation_reason',
        'auto_mode',
        'auto_stop_at',
        'publish_plan',
        'recording_owner_id',
        'stream_condition',
        'onsite_condition',
        'recording_note',
        'recording_tags',
        'thumbnail_path',
        'thumbnail_updated_at',
        'thumbnail_capture_error',
        'viewer_count',
        'peak_viewer_count',
        'boop_count',
        'priority',
        'tags',
        'metadata',
        'required_roles',
        'server_id',
        // Set by the pretalx import; its presence is what stops a slot being imported twice.
        'pretalx_slot_id',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'thumbnail_updated_at' => 'datetime',
        'archived_at' => 'datetime',
        'auto_stop_at' => 'datetime',
        'auto_mode' => 'boolean',
        'recording_tags' => 'array',
        'tags' => 'array',
        'metadata' => 'array',
        'required_roles' => 'array',
    ];

    /**
     * Whether this show is meant to end up as a published recording.
     *
     * The one publishing decision there is. It gates nothing - the uploader still mirrors
     * every source and `recordings.is_published` still decides what a viewer sees - but it
     * is what the audience is told: the schedule's "available later" badge, the archive's
     * pending tile and the recording API all read `yes` off this and nothing else.
     */
    public const PUBLISH_PLANS = ['undecided', 'yes', 'no'];

    /**
     * How the stream capture came back: what the archive uploader mirrored off the
     * source, which happens whether anyone asked for it or not.
     *
     * Two answers, because there is no third move. Whatever went wrong - silence, black,
     * half of it missing - the job is the same: go and get the copy from the room. Naming
     * the fault only asked somebody to classify a thing nobody would ever read back.
     */
    public const STREAM_CONDITIONS = ['ok', 'lost'];

    /**
     * The onsite capture - the room's own recording, off the HyperDeck.
     *
     * This one keeps its detail, because here each answer leads somewhere different.
     * Missing audio can be lifted off the desk afterwards; a missing part is still worth
     * publishing as it stands; only `lost` means there is nothing to cut from.
     */
    public const ONSITE_CONDITIONS = ['ok', 'no_audio', 'no_video', 'incomplete', 'lost'];

    /**
     * How many tags one show may carry, and how long each may be. A cap rather than a
     * vocabulary: the point of the field is that a room writes its own process into it.
     */
    public const MAX_TAGS = 8;

    public const MAX_TAG_LENGTH = 32;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($show) {
            if (empty($show->slug)) {
                $show->slug = Str::slug($show->title.'-'.Carbon::parse($show->scheduled_start)->format('Y-m-d'));
            }

            /*
             * A show scheduled inside a run belongs to it, so neither the form nor the
             * pretalx import has to say so. Only on create: an edit that clears the
             * event means the event was cleared on purpose, and guessing it back on
             * every save would make the field impossible to empty.
             */
            if ($show->event_id === null && $show->scheduled_start) {
                $show->event_id = Event::forDate(Carbon::parse($show->scheduled_start))?->id;
            }
        });

        static::updating(function ($show) {
            // Update peak viewer count if current is higher
            if ($show->viewer_count > $show->peak_viewer_count) {
                $show->peak_viewer_count = $show->viewer_count;
            }
        });
    }

    /**
     * Get the source for this show.
     */
    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * The run of the convention this show is part of. Recordings of the show
     * inherit it. Filed by, never gated on.
     */
    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * What kind of thing this is - a dance, a theatre piece, a musical
     * performance. Recordings of the show inherit it.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the server handling this show.
     */
    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get the users watching this show through its source.
     */
    public function viewers()
    {
        // Use hasManyThrough to get users through source_users
        return $this->hasManyThrough(
            User::class,
            SourceUser::class,
            'source_id',     // Foreign key on source_users table
            'id',            // Foreign key on users table
            'source_id',     // Local key on shows table
            'user_id'        // Local key on source_users table
        );
    }

    /**
     * Get viewer sessions for this show.
     */
    public function viewerSessions()
    {
        return $this->hasMany(SourceUser::class, 'source_id', 'source_id');
    }

    /**
     * Get show statistics for this show.
     */
    public function showStatistics()
    {
        return $this->hasMany(ShowStatistic::class);
    }

    /**
     * Recordings cut from this show's slot.
     *
     * hasMany rather than hasOne: a recording is a time range over the source's archive,
     * so a long block can be sliced into several published pieces without re-recording
     * anything. `recording()` stays as the convenience accessor for the common 1:1 case.
     */
    public function recordings()
    {
        return $this->hasMany(Recording::class);
    }

    /**
     * The primary recording for this show, if one has been cut.
     */
    public function recording()
    {
        return $this->hasOne(Recording::class)->latestOfMany();
    }

    /**
     * Whoever is looking after this show's recording. Planning only: it grants nothing,
     * and a show with no owner is cut the same as one with an owner.
     */
    public function recordingOwner()
    {
        return $this->belongsTo(User::class, 'recording_owner_id');
    }

    /**
     * Where this show stands on having a recording, taken from the recordings cut from it
     * and from how the two captures came back, rather than stored: the artefact is the
     * answer, so nothing can go stale.
     *
     * The order is the argument. A cut that exists ends the story whatever the capture
     * notes say. Below that, the two captures are read as a fallback chain rather than as
     * two parallel jobs: the room's copy only matters once the stream one is gone, and
     * only when both are gone is there nothing left to publish.
     */
    public function recordingState(): string
    {
        $recordings = $this->relationLoaded('recordings')
            ? $this->recordings
            : $this->recordings()->get();

        if ($recordings->isNotEmpty()) {
            if ($recordings->contains(fn ($recording) => $recording->is_published)) {
                return 'published';
            }

            if ($recordings->contains(fn ($recording) => $recording->status === 'failed')) {
                return 'failed';
            }

            if ($recordings->contains(fn ($recording) => $recording->status === 'ready')) {
                return 'ready';
            }

            return 'draft';
        }

        // Nobody is publishing this, so how the captures came back is not a verdict on
        // anything. Above the capture chain on purpose: a show marked `no` must never
        // read as lost.
        if ($this->publish_plan === 'no') {
            return 'skipped';
        }

        if ($this->isLost()) {
            return 'lost';
        }

        // The stream is gone but the room's copy is not, so the cut has to come off the
        // HyperDeck file rather than out of the archive.
        if ($this->stream_condition === 'lost' && $this->onsiteUsable()) {
            return 'onsite';
        }

        return in_array($this->status, ['ended', 'live'], true) ? 'missing' : 'pending';
    }

    /**
     * Whether the room's copy is something anybody can cut from.
     *
     * Everything short of `lost` is: half a show, or a show with no audio on it, is still
     * a show - the audio comes off the desk and the missing part is announced. This is
     * the whole reason the onsite column keeps the detail the stream column dropped.
     */
    public function onsiteUsable(): bool
    {
        return $this->onsite_condition !== null && $this->onsite_condition !== 'lost';
    }

    /**
     * Both captures are gone, so there is nothing to publish and never will be.
     *
     * The only terminal answer on this page, and it takes two verdicts to reach: the
     * stream lost *and* the room's copy lost. A row that says so is off every list of
     * things still to do, which is what marking it is for.
     */
    public function isLost(): bool
    {
        return $this->stream_condition === 'lost' && $this->onsite_condition === 'lost';
    }

    /**
     * The one question the recording plan exists to answer: this has been on air, there is
     * material for it, and it is still not published.
     *
     * Everything the page counts, badges and defaults to is this. Three things take a row
     * off it, and `undecided` is not one of them: `no` is the only opt-out there is, and
     * a row marked `no` is off the page altogether, so anything still on the page is
     * still on the table. Gating this on `yes` meant a show whose material came back
     * perfectly well sat outside the outstanding list purely because nobody had got round
     * to ticking a box - which is the opposite of what an outstanding list is for.
     *
     * The other two are a write-off, which nobody can act on, and a show that has not
     * happened yet, which is not late.
     */
    public function isAwaitingPublication(): bool
    {
        if ($this->publish_plan === 'no' || $this->isLost()) {
            return false;
        }

        if (! in_array($this->status, ['ended', 'live'], true)) {
            return false;
        }

        $recordings = $this->relationLoaded('recordings')
            ? $this->recordings
            : $this->recordings()->get();

        return ! $recordings->contains(fn ($recording) => $recording->is_published);
    }

    /**
     * Awaiting publication with nothing cut at all and no reason recorded for that. The
     * subset worth tinting a row over: everything else on the list is in progress.
     *
     * Deliberately narrower than the list it is drawn from - it wants an explicit `yes`,
     * where the list settles for anything not marked `no`. Somebody committed to this one
     * and there is nothing to show for it. Tinting every undecided row that has not been
     * cut yet would paint most of a running convention red by the second afternoon, and a
     * grid that is all red says nothing.
     */
    public function isRecordingGap(): bool
    {
        if ($this->publish_plan !== 'yes' || ! $this->isAwaitingPublication()) {
            return false;
        }

        $recordings = $this->relationLoaded('recordings')
            ? $this->recordings
            : $this->recordings()->get();

        return $recordings->isEmpty() && $this->stream_condition !== 'lost';
    }

    /**
     * Whether the audience is being told this will be available afterwards. One decision,
     * read in three places: the schedule badge, the archive's pending tiles and the
     * recording API.
     */
    public function willBeAvailable(): bool
    {
        return $this->publish_plan === 'yes';
    }

    /**
     * The tags on this show, always an array and always strings.
     *
     * @return array<int, string>
     */
    public function recordingTags(): array
    {
        return array_values(array_filter(
            array_map('strval', $this->recording_tags ?? []),
            fn (string $tag) => $tag !== '',
        ));
    }

    public function hasRecordingTag(string $tag): bool
    {
        return in_array(mb_strtolower($tag), array_map('mb_strtolower', $this->recordingTags()), true);
    }

    /**
     * Scope for shows nobody has decided about yet.
     */
    public function scopeUndecidedPublishing($query)
    {
        return $query->where('publish_plan', 'undecided');
    }

    /**
     * The query-side twin of isAwaitingPublication(): what is still owed to the audience.
     *
     * Spelled out rather than negated, because `null != 'lost'` is null in SQL and a
     * plain negation would silently drop every row nobody has looked at - which is most
     * of them, and exactly the rows this scope exists to surface.
     */
    public function scopeAwaitingPublication($query)
    {
        return $query->where('publish_plan', '!=', 'no')
            ->whereIn('status', ['ended', 'live'])
            ->where(fn ($inner) => $inner
                ->whereNull('stream_condition')
                ->orWhere('stream_condition', '!=', 'lost')
                ->orWhereNull('onsite_condition')
                ->orWhere('onsite_condition', '!=', 'lost'))
            ->whereDoesntHave('recordings', fn ($inner) => $inner->where('is_published', true));
    }

    /**
     * Get currently active viewers.
     */
    public function activeViewers()
    {
        // Get active viewers from the source
        if ($this->source) {
            return $this->source->activeViewers();
        }

        return collect([]);
    }

    /**
     * Go live - start the actual stream.
     */
    public function goLive()
    {
        // ShowObserver fires ShowWentLive off the status change, so this must not fire it
        // too: the event broadcasts to every viewer, and dispatching it from both places
        // sent the "we are live" message twice for one press.
        $this->update([
            'status' => 'live',
            'actual_start' => now(),
        ]);
    }

    /**
     * End the livestream.
     */
    public function endLivestream()
    {
        $this->update([
            'status' => 'ended',
            'actual_end' => now(),
        ]);

        // Viewer cleanup and the ShowEnded broadcast both live in ShowObserver, which fires
        // off the status change however it was made.
    }

    /**
     * Cancel the show. The optional reason is shown to viewers on the schedule and
     * the show page, so a slot that is only losing its stream can say so instead of
     * reading as the whole item being called off.
     */
    public function cancel(?string $reason = null)
    {
        // ShowObserver fires ShowCancelled off the status change.
        $this->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason ?: null,
        ]);
    }

    /**
     * Set the status by hand, from the pen beside it in /manage.
     *
     * The transition buttons are one-way - there is no Go Live on a cancelled show - so
     * this is the only way back out of a status set in error. It writes the column and
     * lets ShowObserver fire whatever the change means, the same as the buttons do; the
     * two leftovers it does clean up are a cancellation reason on a show that is no longer
     * cancelled, and an out-point on a show that is being put back on air.
     */
    public function setStatus(string $status)
    {
        $changes = ['status' => $status];

        if ($this->status === 'cancelled' && $status !== 'cancelled') {
            $changes['cancellation_reason'] = null;
        }

        if ($status === 'live') {
            $changes['actual_end'] = null;
        }

        $this->update($changes);
    }

    /**
     * Put the show out of the way. Archiving is independent of status: a past year's
     * run, cancelled slots included, is filed away without rewriting what happened to it.
     */
    public function archive()
    {
        if ($this->archived_at === null) {
            $this->update(['archived_at' => now()]);
        }
    }

    public function unarchive()
    {
        if ($this->archived_at !== null) {
            $this->update(['archived_at' => null]);
        }
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Check if show is currently live.
     */
    public function isLive()
    {
        return $this->status === 'live';
    }

    /**
     * Check if show is scheduled.
     */
    public function isScheduled()
    {
        return $this->status === 'scheduled';
    }

    /**
     * Check if show has ended.
     */
    public function hasEnded()
    {
        return $this->status === 'ended';
    }

    /**
     * Check if show is cancelled.
     */
    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    /**
     * Get the RTMP push URL for this show.
     */
    public function getRtmpUrl()
    {
        if ($this->server) {
            return "rtmp://{$this->server->hostname}/live/{$this->source->stream_key}";
        }

        return $this->source->rtmp_url;
    }

    /**
     * Scope for live shows.
     */
    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    /**
     * Scope for scheduled shows.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope for upcoming shows.
     */
    public function scopeUpcoming($query)
    {
        return $query->scheduled()
            ->where('scheduled_start', '>', now())
            ->orderBy('scheduled_start');
    }

    /**
     * Scope for archived shows.
     */
    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Scope for everything not filed away, which is what every current-year view wants.
     */
    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Scope for shows happening today.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('scheduled_start', today());
    }

    /**
     * Get duration in minutes.
     */
    public function getDurationAttribute()
    {
        if ($this->actual_start && $this->actual_end) {
            return $this->actual_start->diffInMinutes($this->actual_end);
        }

        return $this->scheduled_start->diffInMinutes($this->scheduled_end);
    }

    /**
     * The description as HTML.
     *
     * Descriptions are markdown - that is what pretalx abstracts are written in, and what
     * the form accepts - and the stored value stays markdown so it can be edited. This is
     * the rendered, sanitised form for display.
     */
    public function getDescriptionHtmlAttribute(): ?string
    {
        return Markdown::render($this->description);
    }

    /**
     * Get the full URL for the thumbnail path stored in database.
     * Returns a signed URL for S3 access.
     */
    public function getThumbnailUrlAttribute()
    {
        $value = $this->thumbnail_path;
        if (! $value) {
            return null;
        }

        // If it's already a full URL, return it
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        // Return a temporary signed URL (valid for 1 hour)
        try {
            return Storage::disk('s3')->temporaryUrl($value, now()->addHour());
        } catch (\Exception $e) {
            // Fallback to regular URL if temporary URL fails
            return Storage::disk('s3')->url($value);
        }
    }

    /**
     * Get HLS master playlist URL from the show's source.
     */
    public function getHlsUrl()
    {
        if (! $this->source) {
            return null;
        }

        return $this->source->getHlsUrl();
    }

    /**
     * Capture a screenshot from the live stream.
     */
    public function captureScreenshot()
    {
        if ($this->status !== 'live' || ! $this->source) {
            throw new \Exception('Show must be live with an active source to capture screenshot');
        }

        // Use the ThumbnailService to capture the screenshot
        // This ensures proper URL handling for Docker environments
        $thumbnailService = app(ThumbnailService::class);
        $result = $thumbnailService->captureFromHls($this);

        if (! $result) {
            // Get the error from the model if it was set
            $error = $this->thumbnail_capture_error ?? 'Failed to capture screenshot';
            throw new \Exception($error);
        }

        return $result;
    }

    /**
     * Get the stream URL for this show.
     */
    public function getStreamUrl()
    {
        return $this->getHlsUrl();
    }

    /**
     * Check if show can be watched.
     *
     * Live and nothing else. This used to open five minutes before the scheduled
     * start, which was harmless when a channel was only up while its show was, and
     * is not now: a channel sending an empty hall through setup would be watchable
     * on the strength of a slot that had not been put live yet. A viewer arriving
     * early gets the starting-soon page and the player picks the stream up off
     * ShowWentLive.
     */
    public function canWatch()
    {
        return $this->status === 'live';
    }

    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute()
    {
        $duration = $this->duration;
        $hours = floor($duration / 60);
        $minutes = $duration % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    /**
     * Update viewer count.
     */
    public function updateViewerCount()
    {
        $count = $this->source ? $this->source->activeViewers()->count() : 0;
        $this->update(['viewer_count' => $count]);
    }

    /**
     * Check if show is in auto mode.
     */
    public function isAutoMode()
    {
        return $this->auto_mode === true;
    }

    /**
     * The moment an auto-mode show must stop, whatever the source is doing.
     *
     * Falls back to `scheduled_end` when no explicit hard stop is set, which is what auto
     * mode did before the column existed. See docs/admin/auto-mode.md.
     */
    public function autoStopAt(): ?Carbon
    {
        if (! $this->isAutoMode()) {
            return null;
        }

        return $this->auto_stop_at ?? $this->scheduled_end;
    }

    /**
     * Whether the hard stop has passed. This is the dance safety net: a show nobody
     * remembered to end stops on its own instead of recording all night.
     */
    public function isPastAutoStop(): bool
    {
        $stop = $this->autoStopAt();

        return $stop !== null && $stop->lte(now());
    }

    /**
     * Private means only listed roles may watch, and nobody else even sees the show.
     * Public means anyone signed in.
     */
    public function isPrivate(): bool
    {
        return ! empty($this->required_roles);
    }

    /**
     * Check if show is within scheduled time window.
     */
    public function isWithinScheduledTime()
    {
        $now = now();

        // Use lte (less than or equal) and gte (greater than or equal) for inclusive boundaries
        return $this->scheduled_start->lte($now) && $this->scheduled_end->gte($now);
    }

    /**
     * Check if show has passed its scheduled end time.
     */
    public function isPastScheduledEnd()
    {
        // Use lt (less than) for exclusive comparison
        return $this->scheduled_end->lt(now());
    }

    /**
     * Scope for auto mode shows.
     */
    public function scopeAutoMode($query)
    {
        return $query->where('auto_mode', true);
    }

    /**
     * Check if show has access restrictions.
     */
    public function hasAccessRestriction(): bool
    {
        return ! empty($this->required_roles);
    }

    /**
     * Check if a user can access this show.
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
     * Scope for shows accessible by a user.
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
