import type { CallbackDataParams } from 'echarts/types/dist/shared';

export type TooltipParam = CallbackDataParams;

export function asParams(raw: unknown): TooltipParam[] {
    if (Array.isArray(raw)) {
        return raw as TooltipParam[];
    }

    return raw ? [raw as TooltipParam] : [];
}

export function escapeHtml(value: string): string {
    return value.replace(/[&<>"']/g, (char) => {
        switch (char) {
            case '&':
                return '&amp;';
            case '<':
                return '&lt;';
            case '>':
                return '&gt;';
            case '"':
                return '&quot;';
            default:
                return '&#39;';
        }
    });
}

export function numericValue(param: TooltipParam): number | null {
    const value = param.value;

    if (typeof value === 'number') {
        return value;
    }

    if (Array.isArray(value)) {
        const last = value[value.length - 1];

        return typeof last === 'number' ? last : null;
    }

    return null;
}

/**
 * Tooltip body: an optional title line, then rows of swatch · label · value.
 * Text is escaped; values sit in tabular figures so columns line up.
 */
export function tooltipHtml(title: string | null, rows: { color?: string; label: string; value: string }[]): string {
    const head = title ? `<div style="font-weight:600;margin-bottom:4px">${escapeHtml(title)}</div>` : '';
    const body = rows
        .map((row) => {
            const swatch = row.color
                ? `<span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${row.color};margin-right:6px"></span>`
                : '';

            return `<div style="display:flex;justify-content:space-between;gap:16px;line-height:1.6"><span>${swatch}${escapeHtml(row.label)}</span><span style="font-variant-numeric:tabular-nums">${escapeHtml(row.value)}</span></div>`;
        })
        .join('');

    return head + body;
}
