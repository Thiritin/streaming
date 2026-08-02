# Auto mode

How a show starts and stops without anyone pressing a button, and where the safety net is.

Applies only to shows with **Auto mode** on. Everything else is driven by hand from the show's status control.

## The two rules

Auto mode is one switch that turns on two independent rules. `shows:check-auto-mode` runs every minute (`app/Console/Kernel.php`) and applies them.

### 1. Start when the source comes online

A show goes live when **all** of these hold:

- `auto_mode` is on
- `status` is `scheduled`
- `scheduled_start` has passed
- its **source is online**

The source check is the point. A show that went live purely on the clock would open an empty stream and a black recording. Auto mode waits for the encoder.

If the source comes up late, the show goes live late - at the next minute tick after the source reports online. `actual_start` is stamped then, so the recording in-point matches what was really broadcast, not the schedule.

If the source never comes up, the show stays `scheduled` and nothing is recorded.

### 2. Stop at the hard stop, whatever the source is doing

A live show ends when:

- `auto_mode` is on
- `status` is `live`
- the **hard stop** has passed

The hard stop is `auto_stop_at`, and falls back to `scheduled_end` when that is empty.

No source check here, deliberately. This is the safety net: a dance scheduled 22:00-01:00 where the encoder keeps pushing after the room empties, and nobody remembers to press End Stream, would otherwise record until someone notices in the morning. The hard stop cuts it.

## Hard stop versus scheduled end

They answer different questions, which is why they are separate fields:

| Field | Question it answers | Used by |
|---|---|---|
| `scheduled_end` | When does the programme guide say this slot is over? | public schedule, the grid, "up next" |
| `auto_stop_at` | What is the last moment this may still be recording? | auto mode only |

Leave the hard stop empty and it *is* the scheduled end - the behaviour before the field existed. Set it later than the scheduled end when a slot habitually overruns and you would rather have the tail than a cut. Set it earlier when the recording must not run past a point, whatever the guide says.

The form defaults the hard stop to the scheduled end when auto mode is switched on, so the safe behaviour is what you get without thinking about it.

## What auto mode does not do

- **It does not cancel.** A show whose source never comes online stays `scheduled`. Cancelling is a decision, so it stays with the operator.
- **It does not restart.** Once a show has ended, auto mode will not bring it back if the source returns. Start it by hand, or schedule the next slot.
- **It does not touch a live show's status.** Going live and ending both run through `Show::goLive()` and `Show::endLivestream()`, the same methods the buttons call, so viewer notification and timestamps behave identically either way.
- **It is not autoscaling.** Server capacity is provisioned by hand; see `docs/admin/rebuild-plan.md` 2.9.

## Operating it

On the show form:

```
Auto mode    ☑  start when the source comes online, stop at the hard stop
Hard stop    [ 2026-08-01 01:30 ]   defaults to the scheduled end
```

With auto mode off, both rules are off and the hard stop is ignored.

## Where it lives

| Piece | File |
|---|---|
| The two rules, once a minute | `app/Console/Commands/CheckAutoModeShows.php` |
| Schedule entry | `app/Console/Kernel.php` |
| `autoStopAt()`, `isPastAutoStop()` | `app/Models/Show.php` |
| Column | `database/migrations/2026_07_30_120000_add_auto_stop_at_to_shows_table.php` |
| Tests | `tests/Unit/Commands/CheckAutoModeShowsTest.php`, `tests/Feature/Manage/ShowsTest.php` |

## Log lines to look for

Both rules log at info with the show id and title. When a show stopped and you want to know why:

```
Auto mode: hard stop reached, ending show
  hard_stop: 2026-08-01T01:30:00+02:00
  explicit_hard_stop: true      <- an operator set it, it was not the scheduled end
  source_status: online         <- the encoder was still pushing; the net did its job
```
