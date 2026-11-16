import { Dialog, cn } from '@geocodio/console-ui';
import { ArrowUp, Layers, Loader2, Palette, Search, Wrench, type LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { ModelPicker } from '@/components/accounts/ModelPicker';
import { YakMarkFace } from '@/components/brand/YakMark';
import { csrfToken } from '@/lib/csrfToken';
import { store } from '@/routes/threads/stack/children';
import type { StackData } from '@/types/stack';

const KINDS: { value: string; label: string; icon: LucideIcon; description: string }[] = [
    { value: 'implement', label: 'Implement', icon: Wrench, description: 'Makes the change on its own branch and hands back a summary.' },
    { value: 'research', label: 'Research', icon: Search, description: "Reads and investigates in the parent's sandbox, then writes up what it found." },
    { value: 'mockup', label: 'Mockup', icon: Palette, description: 'Builds a visual mockup on its own branch for you to review.' },
    { value: 'general', label: 'General', icon: Layers, description: 'Any other work, in its own sandbox. No branch.' },
];

/** Sub-threads left before the cap at which the "used" notice starts to show. */
const NEAR_CAP_MARGIN = 2;

const FIELD_ATTRIBUTES = { autoComplete: 'off', 'data-1p-ignore': true, 'data-lpignore': 'true', 'data-form-type': 'other' } as const;

type AddChildDialogProps = {
    threadId: string;
    parentTitle: string;
    stack: Pick<StackData, 'models' | 'parentAccountName' | 'maxChildren'> & { awake: number; limit: number; childCount: number };
    onClose: () => void;
    onAdded: () => void;
};

export function AddChildDialog({ threadId, parentTitle, stack, onClose, onAdded }: AddChildDialogProps) {
    const [name, setName] = useState('');
    const [brief, setBrief] = useState('');
    const [kind, setKind] = useState('implement');
    const [model, setModel] = useState<string | null>('sonnet');
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const activeKind = KINDS.find((entry) => entry.value === kind) ?? KINDS[0];
    const isAtCap = stack.childCount >= stack.maxChildren;
    const remaining = stack.maxChildren - stack.childCount;
    const waitsForSlot = kind !== 'research' && stack.awake >= stack.limit;
    const notice = isAtCap
        ? { text: `This thread has ${stack.childCount} of ${stack.maxChildren} sub-threads. Close one to add another.`, isFailure: true }
        : waitsForSlot
          ? { text: `${stack.awake} of ${stack.limit} sub-threads are awake. This one waits for a free slot.`, isFailure: false }
          : remaining <= NEAR_CAP_MARGIN
            ? { text: `${stack.childCount} of ${stack.maxChildren} sub-threads used. ${remaining} left on this thread.`, isFailure: false }
            : null;
    const canSubmit = name.trim() !== '' && brief.trim() !== '' && !isAtCap && !isSubmitting;

    const submit = () => {
        if (!canSubmit) {
            return;
        }
        setIsSubmitting(true);
        setError(null);
        fetch(store.url(threadId), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ name, brief, kind, model }),
        })
            .then(async (response) => {
                if (!response.ok) {
                    const body = (await response.json().catch(() => ({}))) as { message?: string };
                    throw new Error(body.message ?? 'The sub-thread could not be added.');
                }

                onAdded();
                onClose();
            })
            .catch((failure: unknown) => {
                setError(failure instanceof Error ? failure.message : 'The sub-thread could not be added.');
                setIsSubmitting(false);
            });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !isSubmitting && onClose()}
            title="Add a sub-thread"
            description={`Sub-thread of ${parentTitle}`}
            width="w-[min(660px,100vw)]"
            className="rounded-xl p-3"
            data-testid="stack-add-dialog"
        >
            <div className="flex flex-col gap-3">
                <div className="overflow-hidden rounded-2xl border border-hair bg-panel shadow-[var(--composer-shadow)] transition-[border-color,box-shadow] duration-200 focus-within:border-[var(--accent-edge)] focus-within:shadow-[var(--composer-shadow),0_0_0_3px_var(--composer-focus-ring)] motion-reduce:transition-none">
                    <div className="flex items-center gap-2 border-b border-hair px-4 py-2 text-[12.5px] text-muted">
                        <label htmlFor="stack-add-name" className="shrink-0">Name</label>
                        <input
                            id="stack-add-name"
                            type="text"
                            name="subthread-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            maxLength={60}
                            placeholder="reverb-events"
                            {...FIELD_ATTRIBUTES}
                            className="min-w-0 flex-1 bg-transparent text-[13px] font-medium text-body outline-none placeholder:text-faint"
                            data-testid="stack-add-name"
                        />
                    </div>
                    <div className="flex items-start gap-3 px-4 pb-1 pt-4">
                        <YakMarkFace className="-mt-px size-[30px] shrink-0" />
                        <textarea
                            name="subthread-brief"
                            value={brief}
                            onChange={(event) => setBrief(event.target.value)}
                            onKeyDown={(event) => {
                                if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                                    event.preventDefault();
                                    submit();
                                }
                            }}
                            rows={4}
                            placeholder="What should it do? Say what to leave alone and what to hand back."
                            {...FIELD_ATTRIBUTES}
                            className="min-h-[96px] min-w-0 flex-1 resize-none border-0 bg-transparent px-0 py-[3px] text-[16px] leading-[1.5] tracking-[-0.006em] text-body shadow-none outline-none caret-accent-text placeholder:text-faint"
                            data-testid="stack-add-brief"
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2 px-3 pb-2 pt-2">
                        <div className="inline-flex gap-0.5 rounded-[10px] border border-hair bg-panel-2/60 p-0.5" role="group" aria-label="Kind">
                            {KINDS.map(({ value, label, icon: Icon }) => {
                                const isActive = kind === value;

                                return (
                                    <button
                                        key={value}
                                        type="button"
                                        aria-pressed={isActive}
                                        onClick={() => setKind(value)}
                                        className={cn(
                                            'inline-flex h-[30px] items-center gap-1.5 rounded-lg pl-[9px] pr-[11px] text-[13px] font-medium transition-[color,background-color,transform] duration-150 active:scale-[0.97] motion-reduce:transition-none',
                                            isActive ? 'bg-panel text-body shadow-[0_1px_2px_rgba(0,0,0,0.1),0_0_0_1px_var(--border)]' : 'text-muted hover:text-body',
                                        )}
                                        data-testid={`stack-add-kind-${value}`}
                                    >
                                        <Icon size={14} className={isActive ? 'text-accent-text' : undefined} aria-hidden="true" />
                                        {label}
                                    </button>
                                );
                            })}
                        </div>
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!canSubmit}
                            aria-busy={isSubmitting || undefined}
                            className={cn(
                                'ml-auto inline-flex h-[34px] items-center gap-1.5 whitespace-nowrap rounded-[10px] pl-[11px] pr-[13px] text-[13px] font-semibold tracking-[-0.005em] transition-[transform,filter,background-color] duration-150 motion-reduce:transition-none',
                                canSubmit || isSubmitting
                                    ? 'bg-accent text-accent-ink shadow-[inset_0_1px_0_rgba(255,255,255,0.18),0_1px_2px_rgba(0,0,0,0.16)] hover:brightness-[1.07] active:translate-y-px active:scale-[0.98] disabled:cursor-progress'
                                    : 'cursor-not-allowed bg-hair text-faint',
                            )}
                            data-testid="stack-add-submit"
                        >
                            {isSubmitting ? <Loader2 size={15} className="animate-spin" aria-hidden="true" /> : <ArrowUp size={15} aria-hidden="true" />}
                            {isSubmitting ? 'Starting...' : 'Start sub-thread'}
                        </button>
                    </div>
                    <p className="px-4 pb-3 text-[12.5px] text-muted" data-testid="stack-add-kind-description">
                        <b className="font-semibold text-body">{activeKind.label}.</b> {activeKind.description}
                    </p>
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5 border-t border-hair bg-panel-2/50 px-3 py-2 text-[12.5px] text-muted" data-testid="stack-add-runs-on">
                        <span>Runs on</span>
                        <span className="inline-flex h-7 items-center rounded-lg border border-hair bg-panel px-2 font-medium text-body" data-testid="stack-add-account">
                            {stack.parentAccountName ?? 'the parent account'}
                        </span>
                        <span>with</span>
                        <ModelPicker variant="chip" models={stack.models} value={model} onChange={setModel} disabled={isSubmitting} testId="stack-add-model" />
                    </div>
                </div>
                {notice && (
                    <p className="flex items-center gap-2 rounded-card bg-panel-2 px-3 py-2 text-[12.5px] text-muted" data-testid="stack-add-notice">
                        <span className={cn('size-2 shrink-0 rounded-full', notice.isFailure ? 'bg-fail' : 'bg-warn')} aria-hidden="true" />
                        {notice.text}
                    </p>
                )}
                {error && <p className="text-[12.5px] text-fail" data-testid="stack-add-error">{error}</p>}
            </div>
        </Dialog>
    );
}
