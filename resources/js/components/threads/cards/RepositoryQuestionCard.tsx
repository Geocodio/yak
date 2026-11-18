import { router } from '@inertiajs/react';
import { Button, ConfirmDialog, cn } from '@geocodio/console-ui';
import { useEffect, useState } from 'react';
import { cancel } from '@/routes/threads';
import { store } from '@/routes/threads/repository-answer';
import type { SharedProps } from '@/types/shared';
import type { RepositoryQuestion } from '@/types/threads';

type Props = {
    threadId: string;
    question: RepositoryQuestion;
    /** The channel the request came from; a Slack request can also be answered in its Slack thread. */
    sourceKey?: string;
    canCancel?: boolean;
};

/** "Which repository is this for?": a list of the repositories Yak offers, a Start button for the chosen one, and a way to cancel the request. */
export function RepositoryQuestionCard({ threadId, question, sourceKey, canCancel = false }: Props) {
    const [selected, setSelected] = useState<string | null>(question.selected);
    useEffect(() => setSelected(question.selected), [question.selected]);
    // A repository that stopped being offered cannot stay picked.
    useEffect(() => setSelected((current) => (current !== null && question.options.includes(current) ? current : null)), [question.options]);
    const [isStarting, setIsStarting] = useState(false);
    const [isCancelling, setIsCancelling] = useState(false);
    const [isConfirmingCancel, setIsConfirmingCancel] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const isDisabled = !question.canAnswer || isStarting || isCancelling;

    const start = () => {
        if (selected === null) {
            return;
        }
        setError(null);
        setIsStarting(true);
        router.post(store.url(threadId), { repo: selected }, {
            preserveScroll: true,
            onSuccess: (page) => setError((page.props as unknown as SharedProps).flash.error ?? null),
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Yak could not start in that repository. Try again.'),
            onFinish: () => setIsStarting(false),
        });
    };

    const cancelRequest = () => {
        setError(null);
        setIsCancelling(true);
        router.post(cancel.url(threadId), {}, {
            preserveScroll: true,
            onSuccess: (page) => setError((page.props as unknown as SharedProps).flash.error ?? null),
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Yak could not cancel this request. Try again.'),
            onFinish: () => {
                setIsCancelling(false);
                setIsConfirmingCancel(false);
            },
        });
    };

    return (
        <section className="rounded-card border border-hair bg-warn-soft px-4 py-4" aria-labelledby="repository-question-title" data-testid="card-repository-question">
            <h2 id="repository-question-title" className="text-[15px] font-semibold">{question.prompt}</h2>
            <p className="mt-1 mb-3 text-[13px] text-body">
                Yak could not tell from the message. Work starts when you pick one.{sourceKey === 'slack' ? ' You can also answer in the Slack thread.' : ''}
            </p>
            <div className="flex flex-col gap-2" role="radiogroup" aria-labelledby="repository-question-title">
                {question.options.map((option) => {
                    const isSelected = option === selected;

                    return (
                        <button
                            key={option}
                            type="button"
                            role="radio"
                            aria-checked={isSelected}
                            disabled={isDisabled}
                            onClick={() => setSelected(option)}
                            className={cn(
                                'flex min-h-11 w-full cursor-pointer items-center gap-2.5 rounded-[9px] border px-3 py-2.5 text-left text-[13px] disabled:cursor-not-allowed disabled:opacity-60 sm:min-h-0',
                                isSelected ? 'border-accent bg-accent-soft' : 'border-hair bg-panel hover:bg-panel-2',
                            )}
                            data-testid="repository-option"
                        >
                            <span className={cn('size-4 shrink-0 rounded-full border-[1.5px]', isSelected ? 'border-[5px] border-accent' : 'border-muted')} aria-hidden />
                            <span className="min-w-0 truncate font-mono text-[12.5px]">{option}</span>
                        </button>
                    );
                })}
            </div>
            {error !== null && (
                <p role="alert" id="repository-question-error" className="mt-3 rounded-control bg-fail-soft px-3 py-2 text-[13px]" data-testid="repository-question-error">{error}</p>
            )}
            <div className="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap [&>button]:max-sm:min-h-11 [&>button]:max-sm:w-full">
                <Button variant="primary" pending={isStarting} pendingLabel="Starting…" disabled={isDisabled || selected === null} onClick={start} aria-describedby={error !== null ? 'repository-question-error' : undefined} data-testid="repository-start">
                    {selected === null ? 'Pick a repository' : `Start in ${selected}`}
                </Button>
                {canCancel && (
                    <Button variant="destructive" disabled={isDisabled} onClick={() => setIsConfirmingCancel(true)} data-testid="repository-cancel">
                        Cancel this request
                    </Button>
                )}
            </div>
            {!question.canAnswer && <p className="mt-3 text-[12.5px] text-muted">Only the person who owns this thread can pick the repository.</p>}
            <ConfirmDialog
                open={isConfirmingCancel}
                onOpenChange={(open) => !open && setIsConfirmingCancel(false)}
                title="Cancel this request?"
                body="Yak will not start any work for it."
                confirmLabel="Cancel request"
                busy={isCancelling}
                confirmTestId="repository-cancel-confirm"
                onConfirm={cancelRequest}
            />
        </section>
    );
}
