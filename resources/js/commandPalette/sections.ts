import { rankActions } from './score.ts';
import type { ActionGroup, PaletteAction } from './types.ts';

export type PaletteRowModel = { action: PaletteAction; disabledReason?: string };
export type PaletteSectionModel = { title: string; rows: PaletteRowModel[] };

/**
 * The sections to show for a query. An empty query lists every action that can
 * run, in group order. A typed query ranks inside each group and hides empty
 * groups; an unavailable match stays as a disabled row. A pinned action matches
 * on its own query and its section moves first.
 */
export function buildSections(groups: ActionGroup[], query: string, pinned: { id: string; query: string } | null = null): PaletteSectionModel[] {
    const isEmpty = query.trim() === '';

    const sections = groups
        .map(({ group, actions }): PaletteSectionModel => {
            const candidates = isEmpty ? actions.filter((action) => action.unavailable === undefined) : actions;
            const ranked = rankActions(query, candidates.filter((action) => action.id !== pinned?.id));
            const pinnedAction = candidates.find((action) => action.id === pinned?.id);
            const pinnedMatch = pinnedAction && pinned ? rankActions(pinned.query, [pinnedAction]) : [];

            return {
                title: group,
                rows: [...pinnedMatch, ...ranked].map((action) => ({ action, disabledReason: action.unavailable })),
            };
        })
        .filter((section) => section.rows.length > 0);

    const pinnedIndex = sections.findIndex((section) => section.rows.some((row) => row.action.id === pinned?.id));

    return pinnedIndex > 0 ? [sections[pinnedIndex], ...sections.filter((_, index) => index !== pinnedIndex)] : sections;
}
