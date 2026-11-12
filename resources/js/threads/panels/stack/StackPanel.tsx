import { Button, ConfirmDialog } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { PanelProps } from '@/threads/panels';
import { stop } from '@/routes/threads/stack';
import { AddChildDialog } from './AddChildDialog';
import { AwakeLimitStepper } from './AwakeLimitStepper';
import { ChildRow } from './ChildRow';
import { StackMessages } from './StackMessages';
import { useStack } from './useStack';

export function StackPanel({ thread }: PanelProps) {
    const { stack, isLoading, error, reload } = useStack(thread);
    const [isAdding, setIsAdding] = useState(false);
    const [isConfirmingStop, setIsConfirmingStop] = useState(false);
    const [isStopping, setIsStopping] = useState(false);

    if (stack === null) {
        return (
            <p className="text-[13px] text-muted" data-testid="stack-panel">
                {isLoading ? 'Loading the stack' : (error ?? 'The stack could not be loaded.')}
            </p>
        );
    }

    const stopStack = () => {
        router.post(stop.url(thread.id), {}, { preserveScroll: true, onStart: () => setIsStopping(true), onFinish: () => { setIsStopping(false); setIsConfirmingStop(false); reload(); } });
    };

    return (
        <div className="flex flex-col gap-5" data-testid="stack-panel">
            {stack.parent && (
                <p className="text-[12.5px] text-muted">
                    Part of <span className="font-medium text-body">{stack.parent.title}</span>. A sub-thread cannot start sub-threads of its own.
                </p>
            )}

            {stack.isRoot && <AwakeLimitStepper threadId={thread.id} current={stack.limit.current} max={stack.limit.max} awake={stack.limit.awake} waiting={stack.limit.waiting} canDrive={stack.canDrive} onChanged={reload} />}

            {stack.canDrive && stack.isRoot && thread.ownerSide === 'yak' && (
                <Button variant="secondary" icon={<Plus size={13} />} onClick={() => setIsAdding(true)} className="w-full" data-testid="stack-add">Add a sub-thread</Button>
            )}

            <section>
                <h3 className="text-[13px] font-semibold text-body" data-testid="stack-children-header">Sub-threads <span className="font-normal text-muted">{stack.children.length} of {stack.maxChildren}</span></h3>
                {stack.children.length === 0 ? (
                    <p className="mt-2 text-[12.5px] text-muted" data-testid="stack-empty">No sub-threads yet. Claude can start them, or you can add one.</p>
                ) : (
                    <ul className="mt-2 divide-y divide-hair rounded-card border border-hair bg-panel">
                        {stack.children.map((child) => <ChildRow key={child.id} child={child} onChanged={reload} />)}
                    </ul>
                )}
            </section>

            <StackMessages messages={stack.messages} />

            {stack.canStop && stack.children.length > 0 && (
                <Button variant="secondary" onClick={() => setIsConfirmingStop(true)} data-testid="stack-stop">Stop stack</Button>
            )}

            {isAdding && <AddChildDialog threadId={thread.id} parentTitle={thread.title} awake={stack.limit.awake} limit={stack.limit.current} childCount={stack.children.length} maxChildren={stack.maxChildren} onClose={() => setIsAdding(false)} onAdded={reload} />}
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
        </div>
    );
}
