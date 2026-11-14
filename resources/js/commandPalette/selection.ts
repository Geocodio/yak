import type { PaletteAction, PaletteStep } from './types.ts';

export type Selection = { kind: 'ignore' } | { kind: 'confirm'; confirm: NonNullable<PaletteAction['confirm']> } | { kind: 'step'; step: PaletteStep } | { kind: 'run' };

/** What picking an action does: nothing, ask first, enter a step, or run and close. */
export function decideSelection(action: PaletteAction): Selection {
    if (action.unavailable !== undefined) {
        return { kind: 'ignore' };
    }
    if (action.steps) {
        return { kind: 'step', step: action.steps };
    }
    if (action.confirm) {
        return { kind: 'confirm', confirm: action.confirm };
    }

    return { kind: 'run' };
}

export const pushStep = (stack: PaletteStep[], step: PaletteStep): PaletteStep[] => [...stack, step];

export const popStep = (stack: PaletteStep[]): PaletteStep[] => stack.slice(0, -1);
