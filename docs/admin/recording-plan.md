# Recording plan

What is meant to be published, who is looking after it, whether the material came back
usable, and whether it has reached long-term storage.

At `/manage` > Recording Plan. Reading is open to anyone past the `access-manage` gate;
changing a cell needs `stream.manage`.

## Why it is not the Shows table

The Shows table answers "what is on air and what is next". It is sorted for the running
order, and it hides ended shows by default. Both are wrong for this job, where the
interesting rows are precisely the ones that have finished and produced nothing.

So this page keeps every matching row on screen at once, has no pagination, and edits
every cell in place with no mode to switch on. It is read down a column - who has what,
and what nobody has - rather than across a row, and it is meant to be worked by several
people at once.

## Three questions, kept apart

Every show is tracked along three axes that are deliberately not merged, because they fail
independently and each one has a different person fixing it.

1. **Is it being published?** `publish_plan` - undecided, yes, no.
2. **Did usable material come back?** The two captures, below.
3. **Has it been deposited?** The archive FTP, below.

A show can be published and never archived, archived and never published, or perfectly
captured and still undecided. One column for all three would let whichever is least urgent
go quietly untracked.

## The two captures

There are always two, and they are **not equals**.

The **stream** capture is what the archive uploader mirrored off the source. It happens
whether anyone asks for it or not, and it is the one that carries almost every show.

The **onsite** capture is a local recording made in the room. It exists as a **fallback**.
If the stream came back clean, nobody needs to go and find the card - so the Onsite column
is dimmed for those rows and nobody wastes an afternoon on them. It lights amber only when
the stream capture failed.

| Column | Column on `shows` | Values |
| --- | --- | --- |
| Stream | `stream_condition` | empty, `ok`, `no_audio`, `no_video`, `incomplete`, `lost` |
| Onsite | `onsite_status` | empty, `none`, `expected`, `received`, `unusable` |

That relationship is why **nothing is written off until both have failed**. `lost` on the
stream alone is a job - go and find the card. It only becomes a write-off once the onsite
copy is recorded as `none` (there wasn't one) or `unusable`. A show whose master is
genuinely gone is not an outstanding job, and leaving it in the missing pile forever is how
the missing pile stops being read.

## The archive FTP

Separate from all of the above: the programme mix and the isolated feeds are uploaded to
the archive FTP for keeping, whether or not the show is ever published.

Two chips per row, `PGM` and `ISO`, backed by `archive_pgm_at` and `archive_iso_at`.
Timestamps rather than flags, because *when did that go up* is asked more often than
*did it*; the chip's tooltip shows the time. Re-ticking a chip that is already on leaves
the original time alone.

**PGM** is what "archived" turns on - the isolated feeds are extra and not every show has
them. A row's PGM chip goes amber when the show has happened, has not been written off, and
has nothing on the FTP yet. That is the **To archive** tile.

## The Recording column

Not stored. It is read off the recordings cut from the show and off the two captures, so
it cannot go stale. The order is the argument:

| Reads | When |
| --- | --- |
| Published / Ready / Draft / Failed | A cut exists. Ends the story whatever the capture notes say |
| Not published | Publish? is `no` |
| Onsite master | The room's copy is in hand, waiting to be imported |
| Lost | Both captures are gone |
| Needs onsite | The stream capture failed and the room's copy has not turned up |
| Missing | Nothing cut, the show has aired, and no reason is recorded |
| Pending | Nothing cut, and the show has not started |

A row is a **gap** when Publish? is `yes` and it reads `Missing` - nothing to cut and no
explanation. Gaps are tinted red. The rail badge next to Recording Plan counts everything
still needing a human: gaps plus the shows whose onsite copy is being chased. Write-offs
are left out, because nobody can act on them.

## Working it as a board

- **Me** on any row puts your name on it. Hunting for yourself in a list of thirty names is
  a poor way to claim work, so the button is there until the row is already yours.
- **Mine** filters to your own rows. It is a query-string filter, not a client-side toggle,
  so "here is what is left on your plate" is a link you can send someone.
- **Group by** day, person or source. Grouping by person turns the grid into per-person work
  lists with a count on each band; unassigned rows sort last, because that is the pile still
  to be handed out.
- Tick rows for the bulk bar: set the publish plan, the owner, either capture verdict, or the
  archive chips, for everything ticked at once. **Take these** claims a whole selection.

This is what the page is for on the day the programme lands: two hundred imported slots
arrive `undecided`, and marking a stage's worth one at a time is the thing that does not get
done. It is also how a whole morning is written off after a card failure.

Rows you may not change are skipped rather than failing the batch, and the toast says how
many were written.

## Editing

Each cell saves on its own the moment it changes, to
`PATCH /manage/shows/{show}/recording-plan`. There is no form and no submit button, so a
half-filled row cannot sit unsaved next to a finished one. The cell's border reports where
the save got to: amber saving, green saved, red refused. A refusal leaves what you typed on
screen.

Arrow up and arrow down move between the same cell of neighbouring rows, which is how a
column gets filled in quickly. Enter does the same from a note, Escape drops it.

**None of this gates anything.** The archive uploader mirrors every segment of every source
whatever `publish_plan` says, `recordings.is_published` still decides what a viewer sees, and
cutting a show marked `no` is not blocked anywhere. `shows.recordable`, which the archive
migration dropped, was a gate; this is not a revival of it.

## Filters

Search, event, day, source, publish plan, owner, recording status, Mine and grouping, all in
the query string, so any view of the work is a link. Anything sitting at its default is left
out of the URL, so a shared link carries only what was actually chosen.

**The event defaults to the latest run.** That is the one that is on, or - since most of this
accounting happens after the doors close - the one that just finished. An installation
accumulates a run of shows per event, so opening the page on every show that ever ran would
bury this run's under the last five. Pick another run from the list, **All events** to switch
the filter off, or **No event** for the shows filed under no run at all, which is what a
programme imported before the calendar existed looks like. A day chosen explicitly wins over
the event, so a link to a date in a past run still resolves.

An installation that has never set the calendar up gets **All events** and sees everything,
exactly as it did before events existed.

The rail badge is scoped the same way: a count of shows from three events ago is a number
nobody will ever act on.

One thing to know: a past run is usually filed away in its entirety, so picking one without
turning **Archived** on comes back empty. The empty state says so.

- **Any owner > Nobody** - unassigned work
- **Missing, no reason recorded** - the end-of-event sweep
- **Needs the onsite copy** - what to go and collect
- **Onsite master to import** - files in hand, waiting on `tools/streaming-archiver`
- **Not on the archive FTP yet** - the upload queue
- **Lost for good** - the write-offs, for the report nobody enjoys writing

Archived shows are off unless you ask for them.

The counts along the top describe the rows on screen rather than the whole database, so a
filtered view is also a tally. Each tile is a shortcut to the rows it counted; clicking it
again undoes that.

## Owners

The list offers anyone who could act on it: the same permission set the panel itself lets
in. It also keeps anyone already holding a row, qualified or not - a volunteer who loses the
role keeps the shows they were given, and dropping them would make those cells read as
unassigned when they are not.

## Limits

The grid renders at most 600 rows and says so when it has more; narrow the filters. This is
deliberately not pagination: a plan that is paginated cannot be read down a column.
