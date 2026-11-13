import { router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';
import { limit as limitRoute } from '@/routes/threads/stack';
import { StackAwakeMeter } from './StackAwakeMeter';

/** How many implement, mockup and general sub-threads may be awake at once. */
export function AwakeLimitStepper({ threadId, current, max, awake, waiting, canDrive, onChanged }: { threadId: string; current: number; max: number; awake: number; waiting: number; canDrive: boolean; onChanged: () => void }) {
    const [isSaving, setIsSaving] = useState(false);

    const change = (next: number) => {
        router.patch(limitRoute.url(threadId), { limit: next }, { preserveScroll: true, preserveState: true, onStart: () => setIsSaving(true), onFinish: () => { setIsSaving(false); onChanged(); } });
    };

    const disabled = !canDrive || isSaving;

    return (
        <div className="flex flex-col gap-3 rounded-card bg-panel-2 p-3" data-testid="stack-limit">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <p className="text-[13px] font-medium text-body">Awake at once</p>
                    <p className="text-[12px] text-muted" data-testid="stack-limit-summary">
                        {awake} of {current} awake{waiting > 0 ? ` · ${waiting} waiting for a slot` : ''}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <button type="button" aria-label="Allow one fewer" disabled={disabled || current <= 1} onClick={() => change(current - 1)} data-testid="stack-limit-decrease" className="flex size-6 cursor-pointer items-center justify-center rounded-chip border border-hair bg-panel text-body disabled:cursor-default disabled:opacity-40">
                        <Minus size={12} aria-hidden />
                    </button>
                    <span className="w-4 text-center text-[13px] tabular-nums text-body" data-testid="stack-limit-value">{current}</span>
                    <button type="button" aria-label="Allow one more" disabled={disabled || current >= max} onClick={() => change(current + 1)} data-testid="stack-limit-increase" className="flex size-6 cursor-pointer items-center justify-center rounded-chip border border-hair bg-panel text-body disabled:cursor-default disabled:opacity-40">
                        <Plus size={12} aria-hidden />
                    </button>
                </div>
            </div>
            <StackAwakeMeter awake={awake} max={current} />
        </div>
    );
}
