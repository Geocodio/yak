import { Button, ConfirmDialog } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { Layers, Plus } from 'lucide-react';
import { useState } from 'react';
import { stop } from '@/routes/threads/stack';
import type { PanelProps } from '@/threads/panels';
import { OverviewGroup } from '../environment/OverviewRow';
import { AwakeLimitStepper } from './AwakeLimitStepper';
import { ChildRow } from './ChildRow';
import { StackMessages } from './StackMessages';

/**
 * The first Overview group once a thread has sub-threads, or is one: status and diff counts of each,
 * the Awake at once stepper, and Stop stack. Adding a sub-thread lives in the composer and header menus.
 */
export function StackGroup({ thread, stack = null, reloadStack = () => {}, onAddSubThread }: PanelProps) {
    const [isConfirmingStop, setIsConfirmingStop] = useState(false);
    const [isStopping, setIsStopping] = useState(false);

    if (stack === null || (stack.children.length === 0 && stack.parent === null)) {
        return null;
    }

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
                </>
            }
            testId="stack-panel"
        >
            <div className="flex flex-col gap-4">
                {stack.parent && (
                    <p className="text-[12.5px] text-muted">
                        Part of <span className="font-medium text-body">{stack.parent.title}</span>. A sub-thread cannot start sub-threads of its own.
                    </p>
                )}

                {stack.children.length > 0 && (
                    <ul className="divide-y divide-hair overflow-hidden rounded-[10px] border border-hair bg-panel">
                        {stack.children.map((child) => <ChildRow key={child.id} child={child} onChanged={reloadStack} />)}
                    </ul>
                )}

                {stack.isRoot && <AwakeLimitStepper threadId={thread.id} current={stack.limit.current} max={stack.limit.max} awake={stack.limit.awake} waiting={stack.limit.waiting} canDrive={stack.canDrive} onChanged={reloadStack} />}

                <StackMessages messages={stack.messages} />

                {stack.canStop && stack.children.length > 0 && (
                    <div>
                        <Button variant="secondary" onClick={() => setIsConfirmingStop(true)} data-testid="stack-stop">Stop stack</Button>
                    </div>
                )}
            </div>
            <ConfirmDialog
                open={isConfirmingStop}
                onOpenChange={setIsConfirmingStop}
                title="Stop the whole stack?"
                body="Every running turn in this thread and its sub-threads stops, and waiting sub-threads are dropped. Nothing is deleted."
                confirmLabel="Stop stack"
                onConfirm={stopStack}
                busy={isStopping}
                confirmTestId="stack-stop-confirm"
            />
        </OverviewGroup>
    );
}
