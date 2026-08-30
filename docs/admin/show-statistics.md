# Show statistics

Every live show is sampled once a minute by `RecordShowStatistics`, and the page at
`/manage/shows/{show}/statistics` is built out of those samples. Two numbers are taken
each minute and they count different things.

**Viewers** is how many are watching at that moment: open sessions on the show's source
that have heartbeated in the last three minutes, signed in or not. If an edge reports a
higher figure through the cache, the higher one wins. Peak, average and watch hours on
the report are all derived from this one.

**Unique viewers** is how many different people the show has had since it went on air.
An account counts once however many times it reconnects, and a signed-out viewer counts
once by the key that identifies them across requests. The report shows the last and
therefore largest of these.

## The window changed on 30 August 2026

Unique viewers used to be counted from midnight and only for signed-in accounts. That
made it the wrong number twice over: a source running a morning slot and an evening one
carried the morning's audience into the evening show's figure, a show running past
midnight started again from nothing, and guests never appeared in it at all even though
they were in the viewer count beside it.

Samples written before that date still hold the old figure and nothing rewrites them, so
unique viewers on a show that aired earlier is not comparable with one that aired since.
Viewers, peak, average and watch hours are unaffected - only the unique column changed.
