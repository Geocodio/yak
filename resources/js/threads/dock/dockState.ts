export type PhoneTabId = 'chat' | 'preview' | 'terminal' | 'details';

export type PhoneTab = { id: PhoneTabId; label: string };

/** Chat and Details always show; Preview and Terminal show only when a dock panel with that id is registered. */
export function phoneTabs(registeredDockIds: string[]): PhoneTab[] {
    const tabs: PhoneTab[] = [{ id: 'chat', label: 'Chat' }];

    if (registeredDockIds.includes('preview')) {
        tabs.push({ id: 'preview', label: 'Preview' });
    }

    if (registeredDockIds.includes('terminal')) {
        tabs.push({ id: 'terminal', label: 'Terminal' });
    }

    tabs.push({ id: 'details', label: 'Details' });

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
