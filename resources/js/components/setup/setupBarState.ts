import type { SetupProps } from '@/types/shared';

export type SetupBarState = 'yak-account' | 'broken-account' | 'both' | 'teleport' | 'claude';

/** Only the neutral states can be dismissed; the warn states are about blocked work. */
export function isDismissible(state: SetupBarState): boolean {
    return state === 'both' || state === 'teleport' || state === 'claude';
}

/** The one bar to show, first match wins. A dismissed neutral state resolves to null, not to the next row. */
export function resolveSetupBar(setup: SetupProps | null, dismissed: string[]): SetupBarState | null {
    if (setup === null) {
        return null;
    }

    let state: SetupBarState | null = null;

    if (setup.needsYakAccount) {
        state = 'yak-account';
    } else if (setup.brokenAccount !== null) {
        state = 'broken-account';
    } else if (!setup.hasOwnAccount && !setup.hasDevice) {
        state = 'both';
    } else if (setup.hasOwnAccount && !setup.hasDevice) {
        state = 'teleport';
    } else if (!setup.hasOwnAccount && setup.hasDevice) {
        state = 'claude';
    }

    return state !== null && isDismissible(state) && dismissed.includes(state) ? null : state;
}
