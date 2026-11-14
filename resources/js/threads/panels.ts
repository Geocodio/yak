import type { ComponentType, ReactNode } from 'react';
import type { FilesMode, FocusRequest } from '@/threads/panels/files/filesMode';
import type { StackData } from '@/types/stack';
import type { TeleportData, ThreadCapacity, ThreadComposer, ThreadData, ThreadNotice } from '@/types/threads';
import type { ThreadDiffSummary } from '@/types/thread-changes';

export type PanelProps = {
    thread: ThreadData;
    canDrive: boolean;
    notice?: ThreadNotice | null;
    composerState?: ThreadComposer['state'];
    capacity?: ThreadCapacity | null;
    accountName?: string | null;
    environmentSwitch?: TeleportData['environmentSwitch'];
    /** The thread's stack, loaded once by the page; null until it arrives. */
    stack?: StackData | null;
    reloadStack?: () => void;
    /** The thread's stored diff, loaded once by the page; null until it arrives. */
    diff?: ThreadDiffSummary | null;
    /** True until the page's first diff load settles. */
    isDiffLoading?: boolean;
    /** Opens Files in Changed mode, on one file when a path is given. */
    onOpenChanges?: (path?: string) => void;
    /** Opens another view, such as Preview. */
    onSelectView?: (id: string) => void;
    /** Closes the dock so the conversation shows. */
    onShowConversation?: () => void;
    /** Opens Files in Everything mode, with one file in the editor. */
    onOpenFile?: (path: string) => void;
    /** The Files mode asked for; null lets the view pick by whether the thread has changes. */
    filesMode?: FilesMode | null;
    onFilesModeChange?: (mode: FilesMode) => void;
    onAddSubThread?: () => void;
    onTeleport?: () => void;
    /** Closes the Overview rail when it floats over a view. */
    onCloseRail?: () => void;
    /** The file the Files view was asked to show. */
    focusRequest?: FocusRequest | null;
    /** The Changed / Everything switch, placed at the top of the file tree. */
    modeSwitch?: ReactNode;
};

export type Panel = {
    id: string;
    label: string;
    /** Ascending; ties keep registration order. */
    order: number;
    component: ComponentType<PanelProps>;
    /** Hides the panel for threads it does not apply to. */
    visible: (thread: ThreadData) => boolean;
    /** A component that renders its own count (or nothing) beside the label. */
    badge?: ComponentType<{ thread: ThreadData; diff?: ThreadDiffSummary | null }>;
};

/** One group of the Overview rail. It renders its own heading and hides itself when it has nothing to show. */
export type OverviewSection = {
    id: string;
    /** Ascending; ties keep registration order. */
    order: number;
    component: ComponentType<PanelProps>;
    visible: (thread: ThreadData) => boolean;
};

const overviewSections = new Map<string, OverviewSection>();
const dockPanels = new Map<string, Panel>();

/** Adds a group to the thread page's Overview rail. Registering an id twice replaces it. */
export function registerOverviewSection(section: OverviewSection): void {
    overviewSections.set(section.id, section);
}

/** Adds a view to the thread page's header (preview, files, terminal). */
export function registerDockPanel(panel: Panel): void {
    dockPanels.set(panel.id, panel);
}

function visibleEntries<T extends { order: number; visible: (thread: ThreadData) => boolean }>(registry: Map<string, T>, thread: ThreadData): T[] {
    return [...registry.values()].filter((entry) => entry.visible(thread)).sort((a, b) => a.order - b.order);
}

export function overviewSectionsFor(thread: ThreadData): OverviewSection[] {
    return visibleEntries(overviewSections, thread);
}

export function dockPanelsFor(thread: ThreadData): Panel[] {
    return visibleEntries(dockPanels, thread);
}
