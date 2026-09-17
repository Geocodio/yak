import { useMemo, useSyncExternalStore } from 'react';

/**
 * Resolved design tokens for chart chrome. Read at render time from the
 * document so `light-dark()` has already picked the OS scheme; never
 * hardcoded, so axes, grids and tooltips follow the panel they sit on.
 */
export type ChartTokens = {
    text: string;
    text2: string;
    text3: string;
    border: string;
    borderStrong: string;
    panel: string;
    panel2: string;
    accent: string;
    ok: string;
    warn: string;
    fail: string;
    fontFamily: string;
};

const FALLBACK_LIGHT: ChartTokens = {
    text: '#282a30',
    text2: '#62666d',
    text3: '#9598a1',
    border: '#ebecef',
    borderStrong: '#e0e1e6',
    panel: '#ffffff',
    panel2: '#eef0f2',
    accent: '#503ba3',
    ok: '#3e8e38',
    warn: '#d97a22',
    fail: '#c93f3b',
    fontFamily: 'ui-sans-serif, system-ui, sans-serif',
};

const FALLBACK_DARK: ChartTokens = {
    ...FALLBACK_LIGHT,
    text: '#e6e7ea',
    text2: '#9499a1',
    text3: '#62666e',
    border: '#232428',
    borderStrong: '#303137',
    panel: '#171819',
    panel2: '#232427',
    accent: '#8b7bd8',
    ok: '#6fbe68',
    warn: '#e8985a',
    fail: '#e0655f',
};

const DARK_QUERY = '(prefers-color-scheme: dark)';

export function prefersDark(): boolean {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(DARK_QUERY).matches;
}

export function readChartTokens(): ChartTokens {
    const fallback = prefersDark() ? FALLBACK_DARK : FALLBACK_LIGHT;

    if (typeof document === 'undefined') {
        return fallback;
    }

    const style = getComputedStyle(document.documentElement);
    const read = (name: string, or: string): string => style.getPropertyValue(name).trim() || or;

    return {
        text: read('--text', fallback.text),
        text2: read('--text-2', fallback.text2),
        text3: read('--text-3', fallback.text3),
        border: read('--border', fallback.border),
        borderStrong: read('--border-strong', fallback.borderStrong),
        panel: read('--panel', fallback.panel),
        panel2: read('--panel-2', fallback.panel2),
        accent: read('--accent', fallback.accent),
        ok: read('--ok', fallback.ok),
        warn: read('--warn', fallback.warn),
        fail: read('--fail', fallback.fail),
        fontFamily: read('--font-sans', fallback.fontFamily),
    };
}

/**
 * Fixed eight-slot categorical order, one set per scheme. Slots are assigned
 * by entity, never by rank, so a filter that drops series never repaints
 * the ones that remain.
 */
export const PALETTE_LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'] as const;
export const PALETTE_DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'] as const;

export type Palette = readonly string[];

export function paletteFor(dark: boolean): Palette {
    return dark ? PALETTE_DARK : PALETTE_LIGHT;
}

export function currentPalette(): Palette {
    return paletteFor(prefersDark());
}

/** Slot `n` (1-based) of the palette for the current scheme. */
export function seriesColor(slot: number, palette: Palette = currentPalette()): string {
    return palette[(Math.max(1, slot) - 1) % palette.length];
}

/** Single-series charts wear slot 1 only. */
export function singleHue(palette: Palette = currentPalette()): string {
    return seriesColor(1, palette);
}

/**
 * Stable colour for `key` given the full universe of keys the chart could
 * ever show: the position of the key in the sorted universe picks the slot.
 */
export function colorFor(key: string, keys: readonly string[], palette: Palette = currentPalette()): string {
    const sorted = [...new Set(keys)].sort((a, b) => a.localeCompare(b));
    const index = sorted.indexOf(key);

    return seriesColor((index < 0 ? sorted.length : index) + 1, palette);
}

/** Percentile lines: p50 slot 1, p90 slot 2, p99 slot 8 (the red). */
export function percentileColors(palette: Palette = currentPalette()): { p50: string; p90: string; p99: string } {
    return { p50: seriesColor(1, palette), p90: seriesColor(2, palette), p99: seriesColor(8, palette) };
}

const DURATION_STEPS_MS = [
    100, 250, 500, 1_000, 2_000, 5_000, 10_000, 15_000, 30_000, 60_000, 120_000, 300_000, 600_000, 900_000, 1_800_000, 3_600_000,
    7_200_000, 10_800_000, 21_600_000, 43_200_000, 86_400_000, 172_800_000, 604_800_000,
];

/**
 * A y-axis scale for durations: ticks land on human steps (30s, 5m, 1h, 6h)
 * rather than the round millisecond counts ECharts would pick on its own.
 */
export function niceDurationAxis(maxMs: number, maxTicks = 5): { max: number; interval: number } {
    const target = Math.max(1, maxMs);
    const interval = DURATION_STEPS_MS.find((step) => target / step <= maxTicks) ?? DURATION_STEPS_MS[DURATION_STEPS_MS.length - 1];

    return { interval, max: Math.ceil(target / interval) * interval };
}

function subscribeScheme(onChange: () => void): () => void {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return () => {};
    }

    const query = window.matchMedia(DARK_QUERY);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}

/**
 * The tokens and palette for the scheme in effect, re-read whenever the OS
 * flips light/dark so series colours and chrome repaint together.
 */
export function useChartTheme(): { dark: boolean; tokens: ChartTokens; palette: Palette } {
    const dark = useSyncExternalStore(subscribeScheme, prefersDark, () => false);

    return useMemo(() => ({ dark, tokens: readChartTokens(), palette: paletteFor(dark) }), [dark]);
}
