# Recording plan

What is meant to be published, who is looking after it, and whether usable material came
back.

At `/manage` > Recording Plan. Reading is open to anyone past the `access-manage` gate;
changing a cell needs `stream.manage`.

## The one question

**What has gone out and what has not.** Everything on the page is arranged around that:
it is the first tile, the first status filter, and the number on the rail badge. A show is
on that list when it has been on air, still has no published recording, and its material
is not gone for good.

Not gated on the publish decision being an explicit `yes`. `no` is the only opt-out there
is, and a row marked `no` is off the page altogether, so anything still on the page is
still on the table. Requiring a `yes` meant a show whose material came back perfectly well
sat outside the outstanding list purely because nobody had got round to ticking a box,
which is the opposite of what an outstanding list is for.

Only three things take a row off it: the material is gone for both captures, the show has
not happened yet, or somebody marked it `no`.

## Why it is not the Shows table

The Shows table answers "what is on air and what is next". It is sorted for the running
order, and it hides ended shows by default. Both are wrong for this job, where the
interesting rows are precisely the ones that have finished and produced nothing.

So this page keeps every matching row on screen at once, has no pagination, and edits
every cell in place with no mode to switch on. It is read down a column - who has what,
and what nobody has - rather than across a row, and it is meant to be worked by several
people at once.

## Publish

`shows.publish_plan`: undecided, yes, no.

One column, and it is also the promise to the audience. `yes` is what puts the "available
later" badge on the schedule, what lists the show as pending in the archive, and what the
recording API answers with. There used to be a separate announce flag beside it, and
nothing kept the two in step.

Editable here, in bulk from the toolbar, and on the show's own form under Recording.

**A show marked `no` comes off the page.** It is a decision, not a state of ignorance:
once it is made there is nothing left to do about the row, and it is already out of every
tile, off the rail badge and out of every status filter. Leaving it in the grid only made
the grid longer. This is also what makes marking a run of unrecorded shows `no` in one
bulk action actually clear them out of the way.

Not gone for good - **Skipped** in the toolbar brings them back, which is how a `no` set by
mistake gets undone. Like every filter here, it lives in the query string.

## The two captures

There are always two, and they are **not equals**.

The **stream** capture is what the archive uploader mirrored off the source. It happens
whether anyone asks for it or not, and it carries almost every show.

The **onsite** capture is the room's own recording, off the HyperDeck. It exists as a
**fallback**: if the stream came back clean, nobody needs to go and find the card, so the
Onsite column is dimmed for those rows. It lights amber only when the stream capture is
gone.

| Column | Column on `shows` | Values |
| --- | --- | --- |
| Stream | `stream_condition` | empty, `ok`, `lost` |
| Onsite | `onsite_condition` | empty, `ok`, `no_audio`, `no_video`, `incomplete`, `lost` |

**The stream column has two answers and no more.** Whatever went wrong with it - silence,
black, half of it missing - the next move is the same: go and get the room's copy. Naming
the fault only asked somebody to classify something nobody would read back.

**The onsite column keeps its detail**, because there each answer leads somewhere
different. Missing audio can be lifted off the desk afterwards. A missing part is still
worth publishing, announced as it stands. Only `lost` means there is nothing.

So everything short of `lost` is still publishable, and only `lost` is red.

## Lost

`stream_condition` **and** `onsite_condition` both `lost`. That is the only terminal answer
on the page, and it takes two verdicts to reach.

A lost row is dimmed and drops off every list of things still to do: it is not in To
publish, not on the rail badge, not a gap. Marking both captures lost *is* how a show that
is genuinely gone stops being chased. It stays visible, because the row is worth looking
at twice - and the Lost tile is how to find them all.

## Tags

`shows.recording_tags`: free text, whatever this room tracks. "saved to nas", "handed to
editor", "colour pass".

There is no vocabulary and no settings page for it, on purpose - every convention runs its
recordings slightly differently, and a column per process is wrong again next year. Type a
tag on a row and it joins the suggestion list every other box on the page offers, which is
what keeps thirty people's typing to one vocabulary without anyone defining it first.

Tags are folded to lower case, capped at eight per show, and filterable from the toolbar.
The bulk bar **adds** and **removes** one tag across a selection rather than replacing the
lists, since a selection spans rows carrying different tags.

## The Recording column

Not stored. It is read off the recordings cut from the show and off the two captures, so
it cannot go stale. The order is the argument:

| Reads | When |
| --- | --- |
| Published / Ready / Draft / Failed | A cut exists. Ends the story whatever the capture notes say |
| Not published | Publish is `no` |
| Lost | Both captures are gone |
| From onsite | The stream is gone but the room's copy is usable: cut it from that |
| Missing | Nothing cut, the show has aired, and no reason is recorded |
| Pending | Nothing cut, and the show has not started |

A row is tinted red when it is on the To publish list with nothing cut at all and no
reason recorded, **and** somebody has explicitly marked it `yes`. The tint is narrower than
the list on purpose: somebody committed to that one and there is nothing to show for it.
Tinting every undecided row that has not been cut yet would paint most of a running
convention red by the second afternoon, and a grid that is all red says nothing.

The rail badge next to Recording Plan counts the whole To publish list for the run on
screen.

## Working it as a board

- **Me** on any row puts your name on it. Hunting for yourself in a list of thirty names is
  a poor way to claim work, so the button is there until the row is already yours. Each
  person carries an initials chip in a colour that is always theirs, so a column of rows
  can be scanned for whose is whose.
- **Mine** filters to your own rows. It is a query-string filter, not a client-side toggle,
  so "here is what is left on your plate" is a link you can send someone.
- **Group by** day, person or source. Grouping by person turns the grid into per-person work
  lists with a count on each band; unassigned rows sort last, because that is the pile still
  to be handed out.
- Tick rows for the bulk bar: set the publish plan, the owner, either capture verdict, or
  add and remove a tag, for everything ticked at once. **Take these** claims a whole
  selection. Setting a selection to Skip takes those rows off the page.
