import { createContext, useContext } from 'react';

/** What the composer can ask of the contour field behind it. */
export type StartFieldHandle = {
    /** A ripple of brighter rings, e.g. when the composer takes focus. */
    wave: () => void;
    /** Kick the terrain and raise the "Thread started" peak. */
    erupt: () => void;
    /** Milliseconds of the eruption still to play, so a visit can wait for it. */
    settleMs: () => number;
};

/** An example request a chip drops into the composer. `id` changes on every click so the same example can be picked twice. */
export type StartExample = { id: number; text: string; mode: 'fix' | 'research' };

export const StartFieldContext = createContext<StartFieldHandle | null>(null);

/** The surrounding start screen's field, or null where there is none (the New thread dialog). */
export function useStartField(): StartFieldHandle | null {
    return useContext(StartFieldContext);
}
