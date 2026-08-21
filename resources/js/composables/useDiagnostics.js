/**
 * What the browser can tell us about itself and about playback, collected when a
 * viewer sends a report.
 *
 * The point is that "it keeps buffering" arrives with the browser, the screen, the
 * connection type and the bitrate the player had settled on attached, so nobody has
 * to walk a viewer through dev tools to find out what happened. Everything here is
 * read-only and best-effort: a key that a browser does not expose is left out rather
 * than reported as null, and the server bounds whatever does arrive.
 *
 * Nothing identifying is collected beyond what the request already carries. No
 * fingerprinting, no storage probing, no canvas.
 */

const round = (value, places = 2) =>
  typeof value === 'number' && Number.isFinite(value) ? Number(value.toFixed(places)) : null;

/**
 * Browser and platform, from the UA string and the client hints where they exist.
 * A wrong guess here costs nothing: the raw UA travels with the report as well.
 */
const identifyBrowser = () => {
  const ua = navigator.userAgent ?? '';

  const match = (pattern) => {
    const found = ua.match(pattern);

    return found ? found[1] : null;
  };

  // Order matters: every one of these ships "Chrome" or "Safari" in its UA, so the
  // more specific brand has to win before the generic fallback is reached.
  const browsers = [
    ['Brave', navigator.brave ? '' : null],
    ['Edge', match(/Edg(?:e|A|iOS)?\/([\d.]+)/)],
    ['Opera', match(/OPR\/([\d.]+)/)],
    ['Samsung Internet', match(/SamsungBrowser\/([\d.]+)/)],
    ['Firefox', match(/(?:Firefox|FxiOS)\/([\d.]+)/)],
    ['Chrome', match(/(?:Chrome|CriOS)\/([\d.]+)/)],
    ['Safari', /Safari/.test(ua) ? match(/Version\/([\d.]+)/) ?? '' : null],
  ];

  const hit = browsers.find(([, version]) => version !== null);

  const os =
    match(/(Windows NT [\d.]+)/) ??
    match(/(Mac OS X [\d_.]+)/)?.replace(/_/g, '.') ??
    match(/(Android [\d.]+)/) ??
    match(/(CPU(?: iPhone)? OS [\d_]+)/)?.replace(/_/g, '.') ??
    (/Linux/.test(ua) ? 'Linux' : null);

  return {
    name: hit ? hit[0] : 'Unknown',
    version: hit ? hit[1] : null,
    os,
    mobile: navigator.userAgentData?.mobile ?? /Mobi|Android|iPhone|iPad/.test(ua),
  };
};

const browserFacts = () => {
  const { name, version, os, mobile } = identifyBrowser();

  return {
    name,
    version,
    os,
    mobile,
    userAgent: navigator.userAgent,
    language: navigator.language,
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    cores: navigator.hardwareConcurrency ?? null,
    memoryGb: navigator.deviceMemory ?? null,
  };
};

const screenFacts = () => ({
  viewport: `${window.innerWidth}x${window.innerHeight}`,
  screen: `${window.screen?.width ?? '?'}x${window.screen?.height ?? '?'}`,
  pixelRatio: round(window.devicePixelRatio, 2),
  colorDepth: window.screen?.colorDepth ?? null,
  // A viewer on a phone in landscape hits different edge behaviour from one on a
  // desktop at the same viewport size.
  orientation: window.screen?.orientation?.type ?? null,
});

/**
 * Chromium-only, and worth having where it exists: an effective type of '3g' with a
 * megabit of downlink explains a stall that no server-side metric will.
 */
const connectionFacts = () => {
  const connection = navigator.connection ?? navigator.mozConnection ?? navigator.webkitConnection;

  if (!connection) {
    return null;
  }

  return {
    effectiveType: connection.effectiveType ?? null,
    downlink: connection.downlink ? `${connection.downlink} Mbps` : null,
    rtt: connection.rtt ? `${connection.rtt} ms` : null,
    saveData: connection.saveData ?? false,
  };
};

/**
 * The player's own view of what it is doing, read off vidstack's state and the
 * underlying video element. Same numbers the stats overlay shows, minus the ones
 * that only make sense while watching them tick.
 *
 * @param {object|null} player vidstack media player element
 */
const playbackFacts = (player) => {
  if (!player) {
    return null;
  }

  const state = player.state;

  if (!state) {
    return null;
  }

  const video = player.provider?.video ?? null;
  const quality = state.quality;
  const facts = {
    paused: state.paused,
    muted: state.muted,
    volume: round(state.volume, 2),
    waiting: state.waiting,
    canPlay: state.canPlay,
    live: state.live,
    bufferSeconds: round(Math.max(0, (state.bufferedEnd ?? 0) - (state.currentTime ?? 0)), 1),
    playbackRate: state.playbackRate,
    source: state.source?.src ?? null,
  };

  if (state.live) {
    facts.latencySeconds = round(Math.max(0, (state.seekableEnd ?? 0) - (state.currentTime ?? 0)), 1);
  }

  if (quality) {
    facts.quality = `${quality.width ?? '?'}x${quality.height ?? '?'}`;
    facts.qualityBitrate = quality.bitrate ? `${Math.round(quality.bitrate / 1000)} kbps` : null;
  }

  facts.qualitiesOffered = state.qualities?.length ?? null;
  facts.autoQuality = state.autoQuality ?? null;

  if (video) {
    facts.resolution = video.videoWidth ? `${video.videoWidth}x${video.videoHeight}` : null;
    facts.readyState = video.readyState;
    facts.networkState = video.networkState;

    const played = video.getVideoPlaybackQuality?.();

    if (played) {
      facts.droppedFrames = played.droppedVideoFrames;
      facts.totalFrames = played.totalVideoFrames;
    }
  }

  // Whatever the player last failed on. This is the single most useful field in the
  // whole payload and the one a viewer can never report themselves.
  const error = state.error ?? video?.error ?? null;

  if (error) {
    facts.error = error.message ?? `code ${error.code ?? 'unknown'}`;
  }

  return facts;
};

/**
 * One report's worth of diagnostics.
 *
 * @param {{ player?: object|null, show?: object|null, extra?: object }} options
 */
export const collectDiagnostics = ({ player = null, show = null, extra = {} } = {}) => {
  const diagnostics = {
    page: {
      url: window.location.href,
      referrer: document.referrer || null,
      // A viewer who has had the tab open since yesterday and one who just arrived
      // hit different failure modes.
      openSeconds: round(performance.now() / 1000, 0),
      online: navigator.onLine,
      at: new Date().toISOString(),
    },
    browser: browserFacts(),
    screen: screenFacts(),
  };

  const connection = connectionFacts();

  if (connection) {
    diagnostics.connection = connection;
  }

  const playback = playbackFacts(player);

  if (playback) {
    diagnostics.playback = playback;
  }

  if (show) {
    diagnostics.show = {
      title: show.title ?? null,
      slug: show.slug ?? null,
      status: show.status ?? null,
      source: show.source?.name ?? show.source ?? null,
    };
  }

  return { ...diagnostics, ...extra };
};

/**
 * The same payload flattened for the "what gets sent" list in the dialog. A viewer
 * gets to read every value before it leaves their browser.
 *
 * @returns {Array<{group: string, rows: Array<{label: string, value: string}>}>}
 */
export const describeDiagnostics = (diagnostics) => {
  const label = (key) =>
    key
      .replace(/([A-Z])/g, ' $1')
      .replace(/^./, (character) => character.toUpperCase())
      .trim();

  const display = (value) => {
    if (value === true) return 'Yes';
    if (value === false) return 'No';

    return String(value);
  };

  return Object.entries(diagnostics)
    .filter(([, value]) => value && typeof value === 'object')
    .map(([group, values]) => ({
      group: label(group),
      rows: Object.entries(values)
        .filter(([, value]) => value !== null && value !== undefined && value !== '')
        .map(([key, value]) => ({ label: label(key), value: display(value) })),
    }))
    .filter((group) => group.rows.length > 0);
};
