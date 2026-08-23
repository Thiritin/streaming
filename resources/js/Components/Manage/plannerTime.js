/**
 * Time <-> pixels for the planner, and the snapping that keeps dragged times tidy.
 *
 * One place for the arithmetic so the hour ruler, the source columns and the drag
 * handlers cannot disagree about where 14:00 is. The axis is vertical - hours run
 * down the page - but nothing here cares which way it points.
 */

export const MS_PER_MINUTE = 60_000;
export const MS_PER_HOUR = 3_600_000;

/**
 * How coarse a drag lands. Fifteen minutes is the grid an operator aims at, and it
 * matches the quarter-hour lines the column draws.
 */
export const SNAP_MINUTES = 15;

export const toDate = (value) => (value instanceof Date ? value : new Date(value));

/** Pixels from the top of the window. */
export const offsetOf = (value, from, pxPerHour) =>
  ((toDate(value) - toDate(from)) / MS_PER_HOUR) * pxPerHour;

/** Length in pixels of a span. */
export const lengthOf = (start, end, pxPerHour) =>
  ((toDate(end) - toDate(start)) / MS_PER_HOUR) * pxPerHour;

/** The instant at a given pixel offset, snapped. */
export const timeAt = (offset, from, pxPerHour, snapMinutes = SNAP_MINUTES) => {
  const minutes = (offset / pxPerHour) * 60;

  return new Date(toDate(from).getTime() + snap(minutes, snapMinutes) * MS_PER_MINUTE);
};

export const snap = (minutes, snapMinutes = SNAP_MINUTES) =>
  Math.round(minutes / snapMinutes) * snapMinutes;

/** Shift an instant by a snapped number of minutes. */
export const shift = (value, minutes, snapMinutes = SNAP_MINUTES) =>
  new Date(toDate(value).getTime() + snap(minutes, snapMinutes) * MS_PER_MINUTE);

/**
 * `YYYY-MM-DDTHH:mm:ss` in local time. The server parses it in the app timezone, so it must
 * not carry a Z suffix - toISOString() would silently shift everything to UTC.
 */
export const toLocalIso = (value) => {
  const date = toDate(value);
  const pad = (n) => String(n).padStart(2, '0');

  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
  );
};

export const clockOf = (value) => {
  const date = toDate(value);
  const pad = (n) => String(n).padStart(2, '0');

  return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

/**
 * Two blocks in the same column cannot share time. Used to flag clashes, not to
 * prevent them: sometimes an overlap is real and the operator needs to see it to fix
 * it.
 */
export const overlaps = (a, b) =>
  toDate(a.start) < toDate(b.end) && toDate(b.start) < toDate(a.end);

/**
 * Overlapping blocks laid out side by side inside their column, the way a calendar
 * does it: a group of blocks that touch each other share the width between them.
 *
 * Returns the same blocks with `lane` and `lanes`, which are the column-within-the-
 * column and how many there are.
 */
export const packColumns = (blocks) => {
  const sorted = [...blocks].sort((a, b) => toDate(a.start) - toDate(b.start));
  const packed = [];

  let group = [];
  let groupEnd = null;

  const flush = () => {
    if (!group.length) return;

    // Every block in the group gets the same denominator, so their edges line up.
    const lanes = Math.max(...group.map((entry) => entry.lane)) + 1;
    group.forEach((entry) => packed.push({ ...entry, lanes }));
    group = [];
    groupEnd = null;
  };

  sorted.forEach((block) => {
    if (groupEnd !== null && toDate(block.start) >= groupEnd) {
      flush();
    }

    // The first free lane in the current group, so two blocks only share one when
    // they genuinely overlap.
    const taken = new Set(
      group.filter((entry) => overlaps(entry, block)).map((entry) => entry.lane),
    );

    let lane = 0;
    while (taken.has(lane)) lane += 1;

    group.push({ ...block, lane });
    groupEnd = groupEnd === null
      ? toDate(block.end)
      : new Date(Math.max(groupEnd, toDate(block.end)));
  });

  flush();

  return packed;
};

/**
 * The hour labels down the side, from `from` up to but not including `to`.
 */
export const hoursBetween = (from, to) => {
  const hours = [];

  for (let hour = from; hour < to; hour += 1) {
    hours.push({ hour, label: `${String(hour).padStart(2, '0')}:00` });
  }

  return hours;
};
