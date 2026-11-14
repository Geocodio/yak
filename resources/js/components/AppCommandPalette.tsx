import { router } from '@inertiajs/react';
import { CommandPalette, ConfirmDialog, type CommandPaletteSection } from '@geocodio/console-ui';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import type React from 'react';
import { openNewThread } from '@/components/Sidebar';
import { NEW_THREAD_ID, NEW_THREAD_KEYWORDS, useGlobalActions } from '@/commandPalette/globalActions';
import { splitNewThreadQuery } from '@/commandPalette/newThreadPrompt';
import { isMacPlatform } from '@/commandPalette/platform';
import { KeyboardShortcutsDialog } from '@/commandPalette/KeyboardShortcutsDialog';
import { PALETTE_ATTRIBUTE, ShortcutListener } from '@/commandPalette/ShortcutListener';
import { boostFor, pickedFirst, recordPick } from '@/commandPalette/rankingMemory';
import { pullRequestResultAction, repositoryResultAction, threadResultAction, type ResultEnvironment } from '@/commandPalette/resultActions';
import { usePaletteSearch } from '@/commandPalette/usePaletteSearch';
import { show as showThread } from '@/routes/threads';
import { buildSections } from '@/commandPalette/sections';
import { decideSelection, popStep, pushStep } from '@/commandPalette/selection';
import { keycapsFor } from '@/commandPalette/shortcuts';
import type { PaletteAction, PaletteStep } from '@/commandPalette/types';
import { useActionGroups } from '@/commandPalette/useCommandPaletteActions';

const isMac = isMacPlatform();
const resultEnvironment: ResultEnvironment = { threadUrl: (threadId) => showThread.url(threadId), openNewThread };
const WIDE_SCREEN = '(min-width: 640px)';

function useIsWideScreen(): boolean {
    const [isWide, setIsWide] = useState(() => window.matchMedia(WIDE_SCREEN).matches);

    useEffect(() => {
        const query = window.matchMedia(WIDE_SCREEN);
        const update = () => setIsWide(query.matches);
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return isWide;
}

export function AppCommandPalette() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [steps, setSteps] = useState<PaletteStep[]>([]);
    const [stepActions, setStepActions] = useState<PaletteAction[]>([]);
    const [pendingConfirm, setPendingConfirm] = useState<PaletteAction | null>(null);
    const isWide = useIsWideScreen();
    const groups = useActionGroups();
    useGlobalActions();

    const step = steps.at(-1) ?? null;
    const searchResults = usePaletteSearch(query, open && step === null);

    /** The query each open step returns to when it is left: a result's more step restores the search, other steps start clean. */
    const returnQueries = useRef<string[]>([]);

    const close = useCallback(() => {
        setOpen(false);
        setQuery('');
        setSteps([]);
        returnQueries.current = [];
        setPendingConfirm(null);
    }, []);

    const onOpenChange = (next: boolean) => (next ? setOpen(true) : close());

    useEffect(() => {
        if (step === null) {
            setStepActions([]);

            return;
        }
        let isCurrent = true;
        Promise.resolve(step.items(query))
            .catch((): PaletteAction[] => [])
            .then((items) => {
                if (isCurrent) {
                    setStepActions(items);
                }
            });

        return () => {
            isCurrent = false;
        };
    }, [step, query]);

    const newThreadSplit = step === null ? splitNewThreadQuery(query, NEW_THREAD_KEYWORDS) : null;

    const execute = (action: PaletteAction) => {
        // Rows inside a step (files, sub-threads, a result's own actions) never rank, so only root picks are remembered.
        if (step === null) {
            recordPick(action.id);
        }
        if (action.id === NEW_THREAD_ID && newThreadSplit !== null) {
            openNewThread({ prompt: newThreadSplit.prompt });
        } else if (action.run) {
            action.run();
        } else if (action.href) {
            router.visit(action.href);
        }
        close();
    };

    const enterStep = (next: PaletteStep, returnQuery = '') => {
        returnQueries.current.push(returnQuery);
        setStepActions([]);
        setSteps((stack) => pushStep(stack, next));
        setQuery('');
    };

    const inputRef = useRef<HTMLInputElement>(null);
    const hasStep = step !== null;

    // console-ui remounts the search input when a step starts or ends, which drops focus to the palette container.
    useLayoutEffect(() => {
        if (open && !pendingConfirm) {
            inputRef.current?.focus();
        }
    }, [open, hasStep, pendingConfirm]);

    const select = (action: PaletteAction) => {
        const selection = decideSelection(action);
        if (selection.kind === 'step') {
            enterStep(selection.step);
        } else if (selection.kind === 'confirm') {
            setPendingConfirm(action);
        } else if (selection.kind === 'run') {
            execute(action);
        }
    };

    const selectRef = useRef(select);
    selectRef.current = select;

    useEffect(() => {
        const onOpenRequest = () => setOpen(true);
        const onToggleRequest = () => (open ? close() : setOpen(true));
        const onRunRequest = (event: Event) => {
            const action = (event as CustomEvent<PaletteAction>).detail;
            const { kind } = decideSelection(action);
            if (kind === 'confirm' || kind === 'step') {
                setOpen(true);
            }
            selectRef.current(action);
        };
        window.addEventListener('yak:open-palette', onOpenRequest);
        window.addEventListener('yak:toggle-palette', onToggleRequest);
        window.addEventListener('yak:run-palette-action', onRunRequest);

        return () => {
            window.removeEventListener('yak:open-palette', onOpenRequest);
            window.removeEventListener('yak:toggle-palette', onToggleRequest);
            window.removeEventListener('yak:run-palette-action', onRunRequest);
        };
    }, [open, close]);

    const buildItems = (): CommandPaletteSection[] => {
        const toItem = ({ action, disabledReason }: { action: PaletteAction; disabledReason?: string }) => {
            const more = action.more;

            return {
            id: action.id,
            testId: `palette-action-${action.id}`,
            label: action.label,
            hint: action.hint,
            href: action.href,
            destructive: action.destructive,
            shortcut: action.shortcut && isWide ? keycapsFor(action.shortcut, isMac) : undefined,
            disabled: disabledReason !== undefined,
            disabledReason,
            onSelect: () => select(action),
            ...(more ? { onMore: () => enterStep(more, query), moreLabel: `More actions for ${action.label}` } : {}),
            };
        };

        if (step !== null) {
            return [{ items: stepActions.map((action) => toItem({ action, disabledReason: action.unavailable })) }];
        }

        const pinned = newThreadSplit ? { id: NEW_THREAD_ID, query: query.trim().split(/\s+/)[0] } : null;

        const actionSections = buildSections(groups, query, pinned, boostFor).map((section) => ({ title: section.title, items: section.rows.map(toItem) }));
        const resultSections = [
            { title: 'Threads', rows: searchResults.threads.map((row) => threadResultAction(row, resultEnvironment)) },
            { title: 'Repositories', rows: searchResults.repositories.map((row) => repositoryResultAction(row, resultEnvironment)) },
            { title: 'Pull requests', rows: searchResults.pullRequests.map((row) => pullRequestResultAction(row, resultEnvironment)) },
        ]
            .filter((section) => section.rows.length > 0)
            .map((section) => ({ title: section.title, items: pickedFirst(section.rows, boostFor).map((action) => toItem({ action })) }));

        return [...actionSections, ...resultSections];
    };

    return (
        <>
            <ShortcutListener />
            <KeyboardShortcutsDialog />
            <CommandPalette
                open={open}
                onOpenChange={onOpenChange}
                inputRef={inputRef}
                query={query}
                onQueryChange={setQuery}
                placeholder={step?.placeholder ?? 'Search or run a command…'}
                sections={buildItems()}
                step={step ? { title: step.title } : undefined}
                onBack={() => {
                    setStepActions([]);
                    setSteps(popStep);
                    setQuery(returnQueries.current.pop() ?? '');
                }}
                width="w-screen sm:w-[min(600px,calc(100vw-2rem))]"
                {...{ [PALETTE_ATTRIBUTE]: '' }}
                inputProps={{ 'data-testid': 'app-palette' } as React.InputHTMLAttributes<HTMLInputElement>}
            >
                {pendingConfirm?.confirm ? (
                    <ConfirmDialog
                        open
                        data-testid="palette-confirm"
                        confirmTestId="palette-confirm-accept"
                        cancelTestId="palette-confirm-cancel"
                        onOpenChange={(next) => !next && setPendingConfirm(null)}
                        title={pendingConfirm.confirm.title}
                        body={pendingConfirm.confirm.body}
                        confirmLabel={pendingConfirm.confirm.confirmLabel}
                        destructive={pendingConfirm.destructive}
                        onConfirm={() => execute(pendingConfirm)}
                    />
                ) : null}
            </CommandPalette>
        </>
    );
}
