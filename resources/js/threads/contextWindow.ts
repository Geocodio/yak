import type { ThreadComposer } from '@/types/threads';

export type ContextTone = 'normal' | 'near' | 'full';

export type ContextMeter = {
    tokens: number;
    window: number;
    /** Whole percent, capped at 100. */
    percent: number;
    label: string;
    tone: ContextTone;
};

/** Amber from 80%, and the "nearly full" wording from 95%. */
export const NEAR_PERCENT = 80;
export const FULL_PERCENT = 95;

/** 168420 becomes "168k" and 1000000 becomes "1M". */
export function formatTokens(tokens: number): string {
    if (tokens >= 1_000_000) {
        return `${Number((tokens / 1_000_000).toFixed(1))}M`;
    }

    return tokens >= 1000 ? `${Math.round(tokens / 1000)}k` : String(tokens);
}

/** The window of the model in use. A model the page does not know falls back to the default. */
export function windowFor(windows: ThreadComposer['contextWindows'], model: string | null): number {
    return (model !== null ? windows[model] : undefined) ?? windows.default;
}

/** Null when nothing has been measured yet, so the indicator stays hidden. */
export function contextMeter(context: ThreadComposer['context'], window: number): ContextMeter | null {
    if (context === null || context.tokens <= 0 || window <= 0) {
        return null;
    }

    const exact = (context.tokens / window) * 100;
    const percent = Math.min(100, Math.round(exact));
    const tone: ContextTone = exact >= FULL_PERCENT ? 'full' : exact >= NEAR_PERCENT ? 'near' : 'normal';

    return { tokens: context.tokens, window, percent, label: `${formatTokens(context.tokens)} / ${formatTokens(window)}`, tone };
}

/** Amber from 80% of the step limit. */
export const NEAR_STEP_PERCENT = 80;

export type StepMeter = { steps: number; limit: number | null; percent: number | null; label: string; tone: 'normal' | 'near' };

/** "142 steps", or "262 / 300 steps" for a limited turn. Null when no turn runs. */
export function stepMeter(turn: ThreadComposer['turn']): StepMeter | null {
    if (turn === null) {
        return null;
    }

    if (turn.limit === null) {
        return { steps: turn.steps, limit: null, percent: null, label: `${turn.steps} steps`, tone: 'normal' };
    }

    const percent = Math.min(100, Math.round((turn.steps / turn.limit) * 100));

    return { steps: turn.steps, limit: turn.limit, percent, label: `${turn.steps} / ${turn.limit} steps`, tone: turn.steps / turn.limit >= NEAR_STEP_PERCENT / 100 ? 'near' : 'normal' };
}
