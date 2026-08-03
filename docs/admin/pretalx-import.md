# Importing the programme from pretalx

How sessions in pretalx become shows, and why an imported session cannot be imported twice.

Screen: the **Import from pretalx** button on the Shows table. It has no entry of its own in the rail - it belongs next to the programme it adds to.

## Connecting

Settings > Pretalx holds three values:

| Field | Meaning |
|---|---|
| Instance URL | Root of the pretalx instance, e.g. `https://cfp.example.org` |
| Event slug | The slug in the pretalx URL, e.g. `my-con-2026` |
| API token | Optional. Only needed while the schedule is unpublished, or the event is private |

**Test connection** checks the values as they stand in the form, saved or not, and reports what the credentials reach: how many events they see, and how many sessions the chosen event has in a published schedule. A successful test also loads the event list, after which the slug is a dropdown rather than a text field. The list is remembered per instance, so it survives a reload; test again to refresh it.

The token is write-only: the settings page is told that one is stored, never what it is, and shows a masked value so a stored token is visible as "something is set". Leaving that masked value alone keeps the stored token; **Clear** removes it on the next save.

Until both an instance URL and an event slug are stored, the Import screen is hidden: no rail entry, no button on the Shows table.

The published schedule is read through the pretalx REST API and cached for five minutes. **Reload schedule** on the import screen drops that cache, for when a new schedule version was released mid-event.

## Mapping rooms to channels

Each pretalx room is pinned to one of our sources, and the mapping is saved per event, so it is decided once rather than on every import.

**Only rooms with a channel have their sessions listed.** A convention schedules hundreds of sessions across dozens of rooms - Eurofurence 30 has 344 across 46 - and streams a handful of them. Listing the rest would bury the ones that matter, and they could not be imported anyway. Map a room and its sessions appear immediately, before saving.

The mapping list itself shows only rooms that have sessions in the published schedule, plus any room already mapped, each with its session count. Rooms come from pretalx in its own order; a room that only appears on a slot is still offered, named `Room <id>`.

## What an import creates

One show per selected slot:

| Show field | From |
|---|---|
| Title | Submission title |
| Description | Submission abstract, falling back to its description. Markdown |
| Source | The channel its pretalx room is mapped to |
| Scheduled start / end | The slot's planned times |
| Status | `scheduled` |
| Slug | Title plus start date, made unique |

Everything the streaming side owns - auto mode, recording, access restrictions - is left at its default and edited on the show afterwards. The import never touches an existing show.

Descriptions are markdown, which is what pretalx abstracts are written in: `**_WE'RE BACK!!!_**` reaches viewers as bold italics rather than as asterisks. The stored value stays markdown so it can still be edited; it is rendered for display by `App\Support\Markdown`, which strips raw HTML and unsafe link schemes. Show descriptions written by hand in /manage take markdown too.

Slots that are not actually scheduled (no room or no start time) are not listed at all: there is nothing to place on a timeline.

## Import once

The show carries the pretalx slot id, under a unique index. That is the whole ledger:

- A slot with a show is listed as **Imported**, linking to it, and cannot be ticked.
- Re-posting an already imported slot is skipped and reported, not duplicated.
- **Deleting the show releases the slot**, and it becomes importable again. That is the intended way to redo an import after the programme team moved something.

Nothing else is synced. Editing a show does not write back to pretalx (the API is read-only), and a later change in pretalx does not reach an already imported show - move it in the planner instead, or delete and re-import it.

## Delays

pretalx has no concept of a session running late: its API exposes planned times only, with no live status, actual start, or delay field. What is imported is the plan. Keeping the running order honest once the con is underway is the planner's job, and `actual_start` / `actual_end` on the show are what record what really happened.
