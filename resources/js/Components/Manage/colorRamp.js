/**
 * Derives a Tailwind-style 50-950 ramp from a single hex accent colour.
 *
 * A direct port of App\Support\ColorRamp so the settings page can repaint itself
 * as you pick a colour instead of only after a save. The two have to agree: the
 * preview would be a lie otherwise, so the stop table, the chroma ceiling and the
 * greyscale cutoff below are the same numbers as the PHP, and changing one means
 * changing both.
 */

/** Lightness (%) and a chroma multiplier per stop. */
const STOPS = {
  50: [94.77, 0.78],
  100: [89.5, 1.66],
  200: [80.03, 1.48],
  300: [71.68, 1.32],
  400: [62.48, 1.16],
  500: [53.86, 1.0],
  600: [45.51, 0.84],
  700: [38.07, 0.71],
  800: [29.53, 0.55],
  900: [21.87, 0.41],
  950: [17.17, 0.32],
};

/**
 * Ceiling on the base chroma before it is scaled across the stops. A very
 * saturated accent multiplied up for the light stops lands outside sRGB, where
 * the browser gamut-maps it and the ramp stops being evenly spaced.
 */
const MAX_BASE_CHROMA = 0.13;

const round = (value, places) => {
  const factor = 10 ** places;

  return Math.round(value * factor) / factor;
};

const hexToRgb = (hex) => {
  if (typeof hex !== 'string') {
    return null;
  }

  let value = hex.trim().replace(/^#/, '');

  if (value.length === 3) {
    value = value[0] + value[0] + value[1] + value[1] + value[2] + value[2];
  }

  if (!/^[0-9a-fA-F]{6}$/.test(value)) {
    return null;
  }

  return [
    parseInt(value.slice(0, 2), 16) / 255,
    parseInt(value.slice(2, 4), 16) / 255,
    parseInt(value.slice(4, 6), 16) / 255,
  ];
};

const toLinear = (channel) =>
  channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;

const cbrt = (value) => (value < 0 ? -((-value) ** (1 / 3)) : value ** (1 / 3));

/** @returns {[number, number, number]} lightness %, chroma, hue deg */
const rgbToOklch = (rgb) => {
  const [r, g, b] = rgb.map(toLinear);

  // sRGB -> LMS (Björn Ottosson's Oklab matrices)
  const l = cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

  const labL = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
  const labA = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
  const labB = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;

  let hue = (Math.atan2(labB, labA) * 180) / Math.PI;

  if (hue < 0) {
    hue += 360;
  }

  return [labL * 100, Math.sqrt(labA ** 2 + labB ** 2), hue];
};

/**
 * @param {string|null} hex
 * @returns {Record<string, string>} stop => oklch() string, empty when unparseable
 */
export const rampFromHex = (hex) => {
  const rgb = hexToRgb(hex);

  if (rgb === null) {
    return {};
  }

  let [, chroma, hue] = rgbToOklch(rgb);

  chroma = Math.min(chroma, MAX_BASE_CHROMA);

  // A greyscale accent has no meaningful hue; keep it grey rather than inventing
  // one from floating point noise.
  if (chroma < 0.002) {
    chroma = 0;
    hue = 0;
  }

  const ramp = {};

  for (const [stop, [lightness, chromaScale]] of Object.entries(STOPS)) {
    ramp[stop] = `oklch(${round(lightness, 2)}% ${round(chroma * chromaScale, 4)} ${round(hue, 2)})`;
  }

  return ramp;
};

/** The :root block app.blade.php emits for the saved colour, if there is one. */
const savedPalette = () => document.getElementById('brand-palette');

/**
 * Paint a ramp onto the document as inline custom properties, which outrank the
 * stylesheet.
 *
 * The saved-colour block is switched off for the duration. Without that, picking
 * the built-in neutral would fall back to the *saved* ramp rather than the
 * stylesheet's, and the one preview you cannot see would be the default.
 *
 * Passing a colour that does not parse — or nothing — is how you preview the
 * built-in: the inline properties come off and app.css is left in charge.
 */
export const previewAccent = (hex) => {
  const root = document.documentElement;
  const ramp = rampFromHex(hex);
  const saved = savedPalette();

  if (saved) {
    saved.disabled = true;
  }

  for (const stop of Object.keys(STOPS)) {
    const property = `--color-primary-${stop}`;

    if (ramp[stop]) {
      root.style.setProperty(property, ramp[stop]);
    } else {
      root.style.removeProperty(property);
    }
  }
};

/**
 * Put the page back on whatever is actually saved. Called when leaving the
 * settings screen, so an abandoned edit does not follow you around the panel.
 */
export const clearAccentPreview = () => {
  const root = document.documentElement;
  const saved = savedPalette();

  for (const stop of Object.keys(STOPS)) {
    root.style.removeProperty(`--color-primary-${stop}`);
  }

  if (saved) {
    saved.disabled = false;
  }
};
