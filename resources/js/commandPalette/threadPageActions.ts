import { router } from '@inertiajs/react';
import { toast } from '@geocodio/console-ui';
import { updateThreadShare } from '@/components/threads/share/updateThreadShare';
import { PRIVATE_PUBLISH_MESSAGE, PRIVATE_PUBLISH_TITLE } from '@/components/threads/share/PrivatePublishGate';
import { requestPullRequest } from '@/threads/panels/changes/OpenPullRequestButton';
import { fetchJson } from '@/threads/panels/files/useFileTree';
import { continueMethod, show as showThread, stop } from '@/routes/threads';
import type { StackChild } from '@/types/stack';
import type { FileEntryData, FileTreeResponse } from '@/types/thread-files';
import type { PaletteAction } from './types';

/** The group every thread page action registers under. */
export const THREAD_GROUP = 'This thread';

/** What the thread page hands to its own actions; the handlers are read when an action runs. */
export type ShowActionOptions = {
    threadId: string;
    dockPanelIds: string[];
    hasChanges: boolean;
    parent: { id: string; title: string } | null;
    canAddSubThread: boolean;
    teleportLabel: string | null;
    handlers: {
        selectView: (id: string) => void;
        openChanges: () => void;
        showConversation: () => void;
        toggleOverview: () => void;
        addSubThread: () => void;
        teleport: () => void;
        openFile: (path: string) => void;
    };
};

export function showActions({ threadId, dockPanelIds, hasChanges, parent, canAddSubThread, teleportLabel, handlers }: ShowActionOptions): PaletteAction[] {
    const panel = (id: string, label: string, keywords: string[], extras: Pick<PaletteAction, 'shortcut'> = {}): PaletteAction[] =>
        dockPanelIds.includes(id) ? [{ id: `thread-show-${id}`, label, keywords: ['show', 'open', 'panel', ...keywords], ...extras, run: () => handlers.selectView(id) }] : [];

    return [
        ...panel('files', 'Show files', ['browse', 'tree', 'code', 'editor'], { shortcut: 'showFiles' }),
        {
            id: 'thread-show-changes',
            label: 'Show changes',
            keywords: ['show', 'diff', 'review', 'files changed'],
            unavailable: hasChanges ? undefined : 'This thread has no changes yet',
            run: () => handlers.openChanges(),
        },
        ...panel('terminal', 'Show terminal', ['shell', 'console', 'command line'], { shortcut: 'showTerminal' }),
        ...panel('preview', 'Show preview panel', ['site', 'app', 'browser']),
        { id: 'thread-show-conversation', label: 'Show conversation', keywords: ['chat', 'messages', 'close panel'], shortcut: 'showConversation', run: () => handlers.showConversation() },
        { id: 'thread-toggle-overview', label: 'Toggle the overview', keywords: ['rail', 'sidebar', 'environment', 'details'], run: () => handlers.toggleOverview() },
        ...(canAddSubThread ? [{ id: 'thread-add-sub-thread', label: 'Add a sub-thread', keywords: ['stack', 'child', 'split', 'new'], run: () => handlers.addSubThread() }] : []),
        ...(teleportLabel === null ? [] : [{ id: 'thread-teleport', label: teleportLabel, keywords: ['teleport', 'computer', 'device', 'local', 'hand over'], run: () => handlers.teleport() }]),
        ...(parent === null ? [] : [{ id: 'thread-go-to-parent', label: 'Go to the parent thread', keywords: ['up', 'back', 'stack', parent.title], hint: parent.title, run: () => router.visit(showThread.url(parent.id)) }]),
        { id: 'thread-open-file', label: 'Open file', keywords: ['find', 'search', 'editor', 'code'], steps: openFileStep(threadId, handlers.openFile) },
    ];
}

const MAXIMUM_FILE_ROWS = 50;
const MAXIMUM_DIRECTORIES = 60;
const SKIPPED_DIRECTORIES = new Set(['.git', 'node_modules', 'vendor']);

/**
 * A step that lists the repository's files matching the query. Directories load breadth first, one level at a time,
 * and each is fetched once for the life of the step.
 */
export function openFileStep(threadId: string, openFile: (path: string) => void): NonNullable<PaletteAction['steps']> {
    const directories = new Map<string, Promise<FileEntryData[]>>();
    const load = (path: string): Promise<FileEntryData[]> => {
        if (!directories.has(path)) {
            const query = new URLSearchParams({ scope: 'repo', path });
            directories.set(
                path,
                fetchJson<FileTreeResponse>(`/threads/${threadId}/files?${query}`)
                    .then(({ status, body }) => (status === 200 ? (body.entries ?? []) : []))
                    .catch(() => []),
            );
        }

        return directories.get(path) as Promise<FileEntryData[]>;
    };

    return {
        title: 'Open file',
        placeholder: 'Search files…',
        items: async (query) => {
            const words = query.toLowerCase().split(/\s+/).filter(Boolean);
            const matches: PaletteAction[] = [];
            let level = [''];
            let visited = 0;

            while (level.length > 0 && matches.length < MAXIMUM_FILE_ROWS && visited < MAXIMUM_DIRECTORIES) {
                const batch = level.slice(0, MAXIMUM_DIRECTORIES - visited);
                visited += batch.length;
                const listings = await Promise.all(batch.map(load));
                level = [];

                for (const entry of listings.flat()) {
                    if (entry.isDirectory) {
                        if (!SKIPPED_DIRECTORIES.has(entry.name)) {
                            level.push(entry.path);
                        }
                    } else if (matches.length < MAXIMUM_FILE_ROWS && words.every((word) => entry.path.toLowerCase().includes(word))) {
                        matches.push({ id: `thread-file-${entry.path}`, label: entry.path, keywords: [], run: () => openFile(entry.path) });
                    }
                }
            }

            return matches;
        },
    };
}

export type HeaderActionOptions = {
    threadId: string;
    isOwner: boolean;
    visibility: 'team' | 'private';
    canOpenPullRequest: boolean;
    publishWarning: boolean;
    openShareDialog: () => void;
};

export function headerActions({ threadId, isOwner, visibility, canOpenPullRequest, publishWarning, openShareDialog }: HeaderActionOptions): PaletteAction[] {
    const changeVisibility = (mode: 'only_me' | 'everyone', success: string): void => {
        updateThreadShare(threadId, mode, []).then(
            () => {
                router.reload({ only: ['thread', 'publishWarning'] });
                toast.success(success);
            },
            () => toast.error('Could not change who can see this thread.'),
        );
    };

    return [
        ...(isOwner
            ? [
                  { id: 'thread-share', label: 'Share', keywords: ['people', 'invite', 'access', 'permissions'], run: openShareDialog },
                  visibility === 'team'
                      ? {
                            id: 'thread-make-private',
                            label: 'Make private (only me)',
                            keywords: ['visibility', 'hide', 'only me', 'lock'],
                            confirm: { title: 'Make this thread private?', body: 'Only you can see this thread. Nobody else can read its conversation, changes or details.', confirmLabel: 'Make private' },
                            run: () => changeVisibility('only_me', 'This thread is private'),
                        }
                      : {
                            id: 'thread-make-visible',
                            label: 'Share with everyone on the team',
                            keywords: ['visibility', 'make visible', 'team', 'public', 'unlock'],
                            confirm: { title: 'Make this thread visible to the team?', body: 'Everyone on the team can read its conversation, changes and details.', confirmLabel: 'Make visible' },
                            run: () => changeVisibility('everyone', 'This thread is visible to the team'),
                        },
              ]
            : []),
        ...(canOpenPullRequest
            ? [
                  {
                      id: 'thread-create-pull-request',
                      label: 'Open a pull request',
                      keywords: ['pr', 'create', 'publish', 'github', 'review'],
                      confirm: publishWarning ? { title: PRIVATE_PUBLISH_TITLE, body: PRIVATE_PUBLISH_MESSAGE, confirmLabel: 'Open PR anyway' } : undefined,
                      run: () => requestPullRequest(threadId, publishWarning),
                  },
              ]
            : []),
    ];
}

export type ComposerActionOptions = {
    threadId: string;
    isWorking: boolean;
    canStop: boolean;
    canContinue: boolean;
    canFocus: boolean;
    focusComposer: () => void;
};

export function composerActions({ threadId, isWorking, canStop, canContinue, canFocus, focusComposer }: ComposerActionOptions): PaletteAction[] {
    return [
        ...(canStop
            ? [
                  {
                      id: 'thread-stop-turn',
                      label: 'Stop the turn',
                      keywords: ['cancel', 'interrupt', 'halt', 'abort', 'working'],
                      destructive: true,
                      unavailable: isWorking ? undefined : 'No turn is running',
                      confirm: { title: 'Stop the turn?', body: 'Yak stops work on this thread now. Changes made so far stay in the sandbox.', confirmLabel: 'Stop the turn' },
                      run: () => router.post(stop.url(threadId), {}, { preserveScroll: true }),
                  },
              ]
            : []),
        ...(canContinue ? [{ id: 'thread-continue-turn', label: 'Continue the turn', keywords: ['resume', 'go on', 'guard', 'limit'], run: () => router.post(continueMethod.url(threadId), {}, { preserveScroll: true }) }] : []),
        ...(canFocus ? [{ id: 'thread-focus-composer', label: 'Focus the composer', keywords: ['message', 'write', 'type', 'reply', 'input'], run: focusComposer }] : []),
    ];
}

type StackAction = 'takeOver' | 'split' | 'drop';

const STACK_COPY: Record<StackAction, { id: string; label: string; keywords: string[]; title: string; placeholder: string; confirmTitle: (name: string) => string; body: string; confirmLabel: string }> = {
    takeOver: { id: 'take-over', label: 'Take over a sub-thread', keywords: ['override', 'stack', 'child', 'drive'], title: 'Take over', placeholder: 'Pick a sub-thread…', confirmTitle: (name) => `Take over ${name}?`, body: 'Its results and failures stop going to the parent thread. You steer it from here.', confirmLabel: 'Take it over' },
    split: { id: 'split', label: 'Split a sub-thread', keywords: ['divide', 'stack', 'child', 'break up'], title: 'Split', placeholder: 'Pick a sub-thread…', confirmTitle: (name) => `Split up ${name}?`, body: 'The parent thread is asked to split its work into smaller sub-threads or steps.', confirmLabel: 'Split it up' },
    drop: { id: 'drop', label: 'Drop a sub-thread', keywords: ['remove', 'delete', 'cancel', 'stack', 'child'], title: 'Drop', placeholder: 'Pick a sub-thread…', confirmTitle: (name) => `Drop ${name}?`, body: 'The sub-thread closes and the parent thread is told not to wait for it.', confirmLabel: 'Drop it' },
};

/** Take over, split and drop, each a step that picks the sub-thread and asks before acting. Left out when no sub-thread can be overridden. */
export function stackActions(children: StackChild[], act: (action: StackAction, childId: string) => void): PaletteAction[] {
    const overridable = children.filter((child) => child.canOverride && !child.takenOver);
    if (overridable.length === 0) {
        return [];
    }

    return (Object.keys(STACK_COPY) as StackAction[]).map((action) => {
        const copy = STACK_COPY[action];

        return {
            id: `thread-stack-${copy.id}`,
            label: copy.label,
            keywords: copy.keywords,
            steps: {
                title: copy.title,
                placeholder: copy.placeholder,
                items: (query) =>
                    overridable
                        .filter((child) => child.name.toLowerCase().includes(query.trim().toLowerCase()))
                        .map((child) => ({
                            id: `thread-stack-${copy.id}-${child.id}`,
                            label: child.name,
                            keywords: [],
                            destructive: action === 'drop',
                            confirm: { title: copy.confirmTitle(child.name), body: copy.body, confirmLabel: copy.confirmLabel },
                            run: () => act(action, child.id),
                        })),
            },
        };
    });
}
