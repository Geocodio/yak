import type { ComponentType } from 'react';
import type { TeleportData, ThreadCapacity, ThreadComposer, ThreadData, ThreadNotice } from '@/types/threads';

export type PanelProps = { thread: ThreadData; canDrive: boolean; notice?: ThreadNotice | null; composerState?: ThreadComposer['state']; capacity?: ThreadCapacity | null; accountName?: string | null; environmentSwitch?: TeleportData['environmentSwitch'] };

export type Panel = {
    id: string;
    label: string;
    /** Ascending; ties keep registration order. */
    order: number;
    component: ComponentType<PanelProps>;
    /** Hides the panel for threads it does not apply to. */
    visible: (thread: ThreadData) => boolean;
    /** A number shown beside the tab label. */
    count?: (thread: ThreadData) => number;
};

const sidebarPanels = new Map<string, Panel>();
const dockPanels = new Map<string, Panel>();

/** Adds a tab to the thread page's right sidebar. Registering an id twice replaces it. */
export function registerSidebarPanel(panel: Panel): void {
    sidebarPanels.set(panel.id, panel);
}

/** Adds a tab to the thread page's bottom dock (terminal, files, editor). */
export function registerDockPanel(panel: Panel): void {
    dockPanels.set(panel.id, panel);
}

function visiblePanels(registry: Map<string, Panel>, thread: ThreadData): Panel[] {
    return [...registry.values()].filter((panel) => panel.visible(thread)).sort((a, b) => a.order - b.order);
}

export function sidebarPanelsFor(thread: ThreadData): Panel[] {
    return visiblePanels(sidebarPanels, thread);
}

export function dockPanelsFor(thread: ThreadData): Panel[] {
    return visiblePanels(dockPanels, thread);
}
