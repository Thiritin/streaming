/**
 * Time <-> pixels for the planner, and the snapping that keeps dragged times tidy.
 *
 * One place for the arithmetic so the ruler, the lanes and the drag handlers cannot
 * disagree about where 14:00 is.
 */

/**
 * Zoom levels. `pxPerHour` sets the track width; `snapMinutes` is how coarse a drag lands,
 * matched to the zoom so a one-pixel wobble never produces 14:03.
 */
export const ZOOM = {
  hours: {
    key: 'hours',
    label: 'Hours',
    pxPerHour: 64,
    snapMinutes: 15,
    tickHours: 1,
    // The visible cell an operator aims at. Snapping matches it, so a block always lands
    // on a line rather than a pixel near one.
    cellMinutes: 15,
  },
  halfDays: {
    key: 'halfDays',
    label: 'Half-days',
    pxPerHour: 16,
    snapMinutes: 15,
    tickHours: 6,
    cellMinutes: 60,
  },
  days: {
    key: 'days',
    label: 'Days',
    pxPerHour: 5,
    snapMinutes: 30,
    tickHours: 24,
    cellMinutes: 360,
  },
};

export const ZOOM_ORDER = ['hours', 'halfDays', 'days'];

export const MS_PER_MINUTE = 60_000;
export const MS_PER_HOUR = 3_600_000;

export const toDate = (value) => (value instanceof Date ? value : new Date(value));

/** Pixels from the start of the window. */
export const xOf = (value, from, pxPerHour) =>
  ((toDate(value) - toDate(from)) / MS_PER_HOUR) * pxPerHour;

/** Width in pixels of a span. */
export const widthOf = (start, end, pxPerHour) =>
  ((toDate(end) - toDate(start)) / MS_PER_HOUR) * pxPerHour;

/** The instant at a given pixel offset, snapped. */
export const timeAt = (x, from, pxPerHour, snapMinutes) => {
  const minutes = (x / pxPerHour) * 60;

  return new Date(toDate(from).getTime() + snap(minutes, snapMinutes) * MS_PER_MINUTE);
};

export const snap = (minutes, snapMinutes) => Math.round(minutes / snapMinutes) * snapMinutes;

/** Shift an instant by a snapped number of minutes. */
export const shift = (value, minutes, snapMinutes) =>
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
 * Two blocks on the same lane cannot share time. Used to flag clashes, not to prevent
 * them: sometimes an overlap is real and the operator needs to see it to fix it.
 */
export const overlaps = (a, b) =>
  toDate(a.start) < toDate(b.end) && toDate(b.start) < toDate(a.end);

/**
 * Ruler ticks across the window, one per `tickHours`.
 */
export const cellsFor = (days, zoom) => {
  const step = (zoom.cellMinutes / 60) * zoom.pxPerHour;
  const total = days * 24 * zoom.pxPerHour;
  const cells = [];

  for (let x = 0; x <= total + 0.5; x += step) {
    const minutes = (x / zoom.pxPerHour) * 60;

    cells.push({
      x,
      // Three weights: day boundary, hour, and everything finer. Without the hierarchy a
      // dense grid reads as noise.
      isDay: minutes % 1440 === 0,
      isHour: minutes % 60 === 0,
    });
  }

  return cells;
};

export const ticksFor = (from, days, zoom) => {
  const start = toDate(from);
  const total = days * 24;
  const ticks = [];

  for (let hour = 0; hour <= total; hour += zoom.tickHours) {
    const at = new Date(start.getTime() + hour * MS_PER_HOUR);

    ticks.push({
      hour,
      x: hour * zoom.pxPerHour,
      // Midnight gets the date, everything else the clock; at day zoom only midnight
      // exists, which is what makes the day boundaries readable.
      label: at.getHours() === 0 ? at.toLocaleDateString([], { weekday: 'short', day: 'numeric' }) : clockOf(at),
      isMidnight: at.getHours() === 0,
    });
  }

  return ticks;
};
