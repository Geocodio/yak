export type PhoneTabId = 'chat' | 'preview' | 'files' | 'terminal' | 'overview';

export type PhoneTab = { id: PhoneTabId; label: string };

const DOCK_TABS: PhoneTab[] = [
    { id: 'preview', label: 'Preview' },
    { id: 'files', label: 'Files' },
    { id: 'terminal', label: 'Terminal' },
];

/** Chat and Overview always show; Preview, Files and Terminal show only when a dock panel with that id is registered. */
export function phoneTabs(registeredDockIds: string[]): PhoneTab[] {
    return [{ id: 'chat', label: 'Chat' }, ...DOCK_TABS.filter((tab) => registeredDockIds.includes(tab.id)), { id: 'overview', label: 'Overview' }];
}

/** Reads the active dock panel id from a `#panel=preview` hash; anything else is no panel. The retired `changes` id opens Files. */
export function parseDockHash(hash: string): string | null {
    const match = /^#panel=([a-z][a-z0-9-]*)$/.exec(hash);

    if (match === null) {
        return null;
    }

    return match[1] === 'changes' ? 'files' : match[1];
}

export function dockHashFor(id: string | null): string {
    return id === null ? '' : `#panel=${id}`;
}

/** The dock panel a phone tab shows; Chat and Overview show none. */
export function phoneDockIdFor(tab: PhoneTabId): string | null {
    return DOCK_TABS.some((entry) => entry.id === tab) ? tab : null;
}

/** The phone tab that shows a dock panel; an unknown or closed panel falls back to Chat. */
export function phoneTabIdFor(dockId: string | null): PhoneTabId {
    return DOCK_TABS.find((entry) => entry.id === dockId)?.id ?? 'chat';
}
