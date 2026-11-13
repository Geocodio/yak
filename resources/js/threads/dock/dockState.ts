export type PhoneTabId = 'chat' | 'preview' | 'changes' | 'terminal' | 'overview';

export type PhoneTerminalMode = 'shell' | 'files';

export type PhoneTab = { id: PhoneTabId; label: string };

/** Chat and Overview always show; Preview, Changes and Terminal show only when a dock panel with that id is registered. */
export function phoneTabs(registeredDockIds: string[]): PhoneTab[] {
    const tabs: PhoneTab[] = [{ id: 'chat', label: 'Chat' }];

    if (registeredDockIds.includes('preview')) {
        tabs.push({ id: 'preview', label: 'Preview' });
    }

    if (registeredDockIds.includes('changes')) {
        tabs.push({ id: 'changes', label: 'Changes' });
    }

    if (registeredDockIds.includes('terminal')) {
        tabs.push({ id: 'terminal', label: 'Terminal' });
    }

    tabs.push({ id: 'overview', label: 'Overview' });

    return tabs;
}

/** Reads the active dock panel id from a `#panel=preview` hash; anything else is no panel. */
export function parseDockHash(hash: string): string | null {
    const match = /^#panel=([a-z][a-z0-9-]*)$/.exec(hash);

    return match === null ? null : match[1];
}

export function dockHashFor(id: string | null): string {
    return id === null ? '' : `#panel=${id}`;
}

/** The dock panel a phone tab shows; the Terminal tab switches between the shell and the files browser. */
export function phoneDockIdFor(tab: PhoneTabId, mode: PhoneTerminalMode): string | null {
    if (tab === 'preview') {
        return 'preview';
    }

    if (tab === 'changes') {
        return 'changes';
    }

    if (tab === 'terminal') {
        return mode === 'files' ? 'files' : 'terminal';
    }

    return null;
}

/** The phone tab that shows a dock panel; Files lives under the Terminal tab. */
export function phoneTabIdFor(dockId: string | null): PhoneTabId {
    if (dockId === 'files') {
        return 'terminal';
    }

    return dockId === 'preview' || dockId === 'changes' || dockId === 'terminal' ? dockId : 'chat';
}
