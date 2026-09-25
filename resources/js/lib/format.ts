const EMPTY = '–';

/**
 * Human duration from milliseconds: `830ms`, `12.4s`, `4m 12s`, `2h 05m`, `3.1d`.
 */
export function formatMs(ms: number | null): string {
    if (ms === null || !Number.isFinite(ms)) {
        return EMPTY;
    }

    const abs = Math.max(0, ms);

    if (abs < 1000) {
        return `${Math.round(abs)}ms`;
    }

    if (abs < 60_000) {
        return `${(abs / 1000).toFixed(1)}s`;
    }

    if (abs < 3_600_000) {
        const minutes = Math.floor(abs / 60_000);
        const seconds = Math.round((abs % 60_000) / 1000);

        return seconds === 0 ? `${minutes}m` : `${minutes}m ${seconds}s`;
    }

    if (abs < 86_400_000) {
        const hours = Math.floor(abs / 3_600_000);
        const minutes = Math.round((abs % 3_600_000) / 60_000);

        return `${hours}h ${String(minutes).padStart(2, '0')}m`;
    }

    return `${(abs / 86_400_000).toFixed(1)}d`;
}

export function formatCount(n: number | null): string {
    if (n === null || !Number.isFinite(n)) {
        return EMPTY;
    }

    return n.toLocaleString('en-US', { maximumFractionDigits: 1 });
}

export function formatUsd(n: number | null, digits = 2): string {
    if (n === null || !Number.isFinite(n)) {
        return EMPTY;
    }

    return `$${n.toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits })}`;
}

export function formatPct(n: number | null): string {
    if (n === null || !Number.isFinite(n)) {
        return EMPTY;
    }

    return `${n.toLocaleString('en-US', { maximumFractionDigits: 1 })}%`;
}

/**
 * `2026-09-12` (or any ISO date) → `Sep 12`. Date-only strings are parsed
 * as local midnight so the label never slips a day across time zones.
 */
export function formatDay(iso: string): string {
    const date = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T00:00:00`) : new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

/** ISO timestamp → `Sep 12 14:00`. */
export function formatHour(iso: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    const day = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    const time = date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });

    return `${day} ${time}`;
}

/** ISO timestamp → age relative to now: `just now`, `42s ago`, `5m ago`, `3h ago`, `2d ago`. */
export function formatAgo(iso: string, now: number = Date.now()): string {
    const time = new Date(iso).getTime();

    if (Number.isNaN(time)) {
        return iso;
    }

    const seconds = Math.max(0, Math.floor((now - time) / 1000));

    if (seconds < 5) {
        return 'just now';
    }

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ago`;
    }

    if (seconds < 86_400) {
        return `${Math.floor(seconds / 3600)}h ago`;
    }

    return `${Math.floor(seconds / 86_400)}d ago`;
}
