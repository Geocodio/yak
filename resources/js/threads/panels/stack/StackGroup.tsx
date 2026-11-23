import { Button, ConfirmDialog, Menu } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { Layers, MoreHorizontal, OctagonX, Plus } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { THREAD_GROUP, stackActions } from '@/commandPalette/threadPageActions';
import { useCommandPaletteActions } from '@/commandPalette/useCommandPaletteActions';
import { drop, split, stop, takeOver } from '@/routes/threads/stack';
import type { PanelProps } from '@/threads/panels';
import { OverviewGroup } from '../environment/OverviewRow';
import { useAgents } from '@/threads/panels/agents/AgentsContext';
import { SUB_THREAD_DEFINITION } from '@/threads/panels/agents/words';
import { AwakeLimitEditor, AwakeLimitSummary, useAwakeLimit } from './AwakeLimit';
import { ChildRow } from './ChildRow';

/**
 * The first Overview group once a thread has sub-threads, or is one: each sub-thread with its state, why a waiting
 * one waits, how many with their own sandbox run at once, and Stop all sub-threads in the overflow menu. Adding a sub-thread lives here, in the composer menu and in the command palette.
 */
export function StackGroup({ thread, stack = null, reloadStack = () => {}, onAddSubThread }: PanelProps) {
    const agents = useAgents();
    const [isConfirmingStop, setIsConfirmingStop] = useState(false);
    const [isStopping, setIsStopping] = useState(false);
    const [isEditingLimit, setIsEditingLimit] = useState(false);
    const { save: saveLimit, isSaving: isSavingLimit } = useAwakeLimit(thread.id, reloadStack);

    const children = stack?.children;
    const reloadRef = useRef(reloadStack);
    reloadRef.current = reloadStack;
    const actions = useMemo(
        () =>
            stackActions(children ?? [], (action, childId) => {
                const route = { takeOver, split, drop }[action];
                router.post(route.url(childId), {}, { preserveScroll: true, onFinish: () => reloadRef.current() });
            }),
        [children],
    );
    useCommandPaletteActions(THREAD_GROUP, actions);

    if (stack === null || (stack.children.length === 0 && stack.parent === null)) {
        return null;
    }

    const hasOwnSandboxChild = stack.children.some((child) => child.kind !== 'research');
    const waiting = { awake: stack.limit.awake, current: stack.limit.current, max: stack.limit.max, canRaise: stack.canDrive && stack.isRoot, isSaving: isSavingLimit, onRaise: () => saveLimit(stack.limit.current + 1) };

    const stopStack = () => {
        router.post(stop.url(thread.id), {}, { preserveScroll: true, onStart: () => setIsStopping(true), onFinish: () => { setIsStopping(false); setIsConfirmingStop(false); reloadStack(); } });
    };

    return (
        <OverviewGroup
            title="Sub-threads"
            icon={<Layers size={12} aria-hidden />}
            aside={
                <>
                    <span className="font-medium normal-case tracking-normal tabular-nums" data-testid="stack-children-header">
                        {stack.children.length} of {stack.maxChildren}
                    </span>
                    {onAddSubThread !== undefined && (
                        <button type="button" onClick={onAddSubThread} aria-label="Add a sub-thread" title="Add a sub-thread" className="ml-auto inline-flex size-[26px] cursor-pointer items-center justify-center rounded-[6px] text-muted hover:bg-panel-2 hover:text-body" data-testid="stack-add">
                            <Plus size={13} aria-hidden />
                        </button>
                    )}
                    {stack.canStop && stack.children.length > 0 && (
                        <Menu
                            trigger={<MoreHorizontal size={13} aria-hidden />}
                            aria-label="Sub-threads menu"
                            className={onAddSubThread === undefined ? 'ml-auto size-[26px] px-0' : 'size-[26px] px-0'}
                            align="end"
                            items={[{ key: 'stop', label: 'Stop all sub-threads', icon: <OctagonX size={13} />, danger: true, testId: 'stack-stop', onSelect: () => setIsConfirmingStop(true) }]}
                            data-testid="stack-menu"
                        />
                    )}
                </>
            }
            testId="stack-panel"
        >
            <div className="flex flex-col gap-4">
                <p className="text-[12.5px] text-muted" data-testid="stack-definition">
                    {SUB_THREAD_DEFINITION}
                    {agents !== null && (
                        <>
                            {' '}
                            <Button variant="link" onClick={() => agents.openPanel()} data-testid="stack-view-agents">
                                View in Agents
                            </Button>
                        </>
                    )}
                </p>
                {stack.parent && (
                    <p className="text-[12.5px] text-muted">
                        Part of <span className="font-medium text-body">{stack.parent.title}</span>. A sub-thread cannot start sub-threads of its own.
                    </p>
                )}

                {stack.children.length > 0 && (
                    <ul className="divide-y divide-hair overflow-hidden rounded-[10px] border border-hair bg-panel">
                        {stack.children.map((child) => <ChildRow key={child.id} child={child} waiting={waiting} onChanged={reloadStack} />)}
                    </ul>
                )}

                {hasOwnSandboxChild && stack.isRoot && (isEditingLimit ? (
                    <AwakeLimitEditor current={stack.limit.current} max={stack.limit.max} isSaving={isSavingLimit} onSave={(limit) => saveLimit(limit, () => setIsEditingLimit(false))} onCancel={() => setIsEditingLimit(false)} />
                ) : (
                    <AwakeLimitSummary current={stack.limit.current} canDrive={stack.canDrive} onChange={() => setIsEditingLimit(true)} />
                ))}
            </div>
            <ConfirmDialog
                open={isConfirmingStop}
                onOpenChange={setIsConfirmingStop}
                title="Stop all sub-threads?"
                body="Every running turn in this thread and its sub-threads stops, and waiting sub-threads are dropped. Nothing is deleted."
                confirmLabel="Stop all sub-threads"
                onConfirm={stopStack}
                busy={isStopping}
                confirmTestId="stack-stop-confirm"
            />
        </OverviewGroup>
    );
}
