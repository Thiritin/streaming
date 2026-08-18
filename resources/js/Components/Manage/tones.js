/**
 * Tone name -> classes. The server sends a tone; the client never picks a colour from a
 * raw domain value. Every surface that shows state goes through this map, so the table,
 * the badges and the status strip cannot drift apart.
 */
export const toneText = {
  live: 'text-state-live',
  ok: 'text-state-ok',
  warn: 'text-state-warn',
  idle: 'text-state-idle',
  danger: 'text-state-danger',
  info: 'text-state-info',
};

export const toneBadge = {
  live: 'text-state-live bg-state-live/12 ring-state-live/30',
  ok: 'text-state-ok bg-state-ok/12 ring-state-ok/30',
  warn: 'text-state-warn bg-state-warn/12 ring-state-warn/30',
  idle: 'text-fg-2 bg-fg-3/10 ring-fg-3/25',
  danger: 'text-state-danger bg-state-danger/12 ring-state-danger/30',
  info: 'text-state-info bg-state-info/12 ring-state-info/30',
};

export const toneButton = {
  live: 'text-state-live hover:bg-state-live/12 border-state-live/35',
  ok: 'text-state-ok hover:bg-state-ok/12 border-state-ok/35',
  warn: 'text-state-warn hover:bg-state-warn/12 border-state-warn/35',
  idle: 'text-fg-2 hover:bg-surface-3 border-hairline',
  danger: 'text-state-danger hover:bg-state-danger/12 border-state-danger/35',
  info: 'text-fg-1 hover:bg-surface-3 border-hairline',
};

export const toneDot = {
  live: 'bg-state-live',
  ok: 'bg-state-ok',
  warn: 'bg-state-warn',
  idle: 'bg-state-idle',
  danger: 'bg-state-danger',
  info: 'bg-state-info',
};

export const resolve = (map, tone, fallback = 'idle') => map[tone] ?? map[fallback];

/** SVG fills for charts. Same tones, as an area under a line rather than text. */
export const toneFill = {
  live: 'fill-state-live/15',
  ok: 'fill-state-ok/15',
  warn: 'fill-state-warn/15',
  idle: 'fill-fg-3/10',
  danger: 'fill-state-danger/15',
  info: 'fill-state-info/15',
};

export const toneFillSolid = {
  live: 'fill-state-live',
  ok: 'fill-state-ok',
  warn: 'fill-state-warn',
  idle: 'fill-fg-3',
  danger: 'fill-state-danger',
  info: 'fill-state-info',
};
