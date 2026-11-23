import { Button } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';
import { limit as limitRoute } from '@/routes/threads/stack';

/** Saves the limit of sub-threads with their own sandbox that run at once. */
export function useAwakeLimit(threadId: string, onChanged: () => void): { save: (limit: number, onDone?: () => void) => void; isSaving: boolean } {
    const [isSaving, setIsSaving] = useState(false);

    const save = (limit: number, onDone?: () => void) => {
        router.patch(limitRoute.url(threadId), { limit }, { preserveScroll: true, preserveState: true, onStart: () => setIsSaving(true), onFinish: () => { setIsSaving(false); onDone?.(); onChanged(); } });
    };

    return { save, isSaving };
}

/** "3 can run at the same time · Change". */
export function AwakeLimitSummary({ current, canDrive, onChange }: { current: number; canDrive: boolean; onChange: () => void }) {
    return (
        <p className="text-[12px] text-muted" data-testid="stack-limit-summary">
            {current} can run at the same time
            {canDrive && (
                <>
                    {' · '}
                    <Button variant="link" onClick={onChange} data-testid="stack-limit-change">Change</Button>
                </>
            )}
        </p>
    );
}

/** The inline editor behind "Change": a stepper with Save and Cancel. */
export function AwakeLimitEditor({ current, max, isSaving, onSave, onCancel }: { current: number; max: number; isSaving: boolean; onSave: (limit: number) => void; onCancel: () => void }) {
    const [draft, setDraft] = useState(current);
    const stepClass = 'grid size-9 cursor-pointer place-items-center rounded-[8px] border border-hair bg-panel text-body disabled:cursor-default disabled:opacity-40';

    return (
        <div className="flex flex-col gap-2.5 rounded-card border border-hair bg-panel p-3" data-testid="stack-limit-editor">
            <p className="text-[13px] font-semibold text-body">Running at the same time</p>
            <p className="text-[12px] text-muted">Sub-threads with their own sandbox take turns. Allowing more finishes the work sooner but uses more of your Claude account at once. Research sub-threads don&apos;t count toward this.</p>
            <div className="flex items-center gap-2">
                <button type="button" aria-label="Allow fewer at a time" disabled={isSaving || draft <= 1} onClick={() => setDraft(draft - 1)} className={stepClass} data-testid="stack-limit-decrease">
                    <Minus size={14} aria-hidden />
                </button>
                <span className="min-w-7 text-center text-[15px] font-semibold tabular-nums text-body" data-testid="stack-limit-value">{draft}</span>
                <button type="button" aria-label="Allow more at a time" disabled={isSaving || draft >= max} onClick={() => setDraft(draft + 1)} className={stepClass} data-testid="stack-limit-increase">
                    <Plus size={14} aria-hidden />
                </button>
                <span className="text-[12px] text-muted">Max {max}</span>
            </div>
            <div className="flex gap-2">
                <Button variant="primary" onClick={() => onSave(draft)} pending={isSaving} data-testid="stack-limit-save">Save</Button>
                <Button variant="secondary" onClick={onCancel} disabled={isSaving} data-testid="stack-limit-cancel">Cancel</Button>
            </div>
        </div>
    );
}
