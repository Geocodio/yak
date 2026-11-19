import { router } from '@inertiajs/react';
import { Button, ConfirmDialog, cn } from '@geocodio/console-ui';
import { useEffect, useRef, useState } from 'react';
import { cardDraftKey, useCardDraft } from '@/components/threads/cards/useCardDraft';
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
    const [selected, setSelected] = useCardDraft<string | null>(cardDraftKey(threadId, 'repository_question', 'selected'), question.selected);
    const syncedSelected = useRef(question.selected);
    useEffect(() => {
        if (syncedSelected.current !== question.selected) {
            syncedSelected.current = question.selected;
            setSelected(question.selected);
        }
    }, [question.selected, setSelected]);
    // A repository that stopped being offered cannot stay picked.
    useEffect(() => setSelected((current) => (current !== null && question.options.includes(current) ? current : null)), [question.options]);
    const hasOtherOptions = question.otherOptions.length > 0;
    const [isOtherChosen, setIsOtherChosen] = useCardDraft<boolean>(cardDraftKey(threadId, 'repository_question', 'isOtherChosen'), false);
    const [otherChoice, setOtherChoice] = useCardDraft<string>(cardDraftKey(threadId, 'repository_question', 'otherChoice'), '');
    const isOther = hasOtherOptions && (isOtherChosen || question.options.length === 0);
    const chosen = isOther ? (question.otherOptions.includes(otherChoice) ? otherChoice : null) : selected;
    const [isStarting, setIsStarting] = useState(false);
    const [isCancelling, setIsCancelling] = useState(false);
    const [isConfirmingCancel, setIsConfirmingCancel] = useState(false);
    const [error, setError] = useCardDraft<string | null>(cardDraftKey(threadId, 'repository_question', 'error'), null);
    const isDisabled = !question.canAnswer || isStarting || isCancelling;

    const start = () => {
        if (chosen === null) {
            return;
        }
        setError(null);
        setIsStarting(true);
        router.post(store.url(threadId), { repo: chosen }, {
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
                    const isSelected = !isOther && option === selected;

                    return (
                        <button
                            key={option}
                            type="button"
                            role="radio"
                            aria-checked={isSelected}
                            disabled={isDisabled}
                            onClick={() => {
                                setSelected(option);
                                setIsOtherChosen(false);
                            }}
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
                {hasOtherOptions && (
                    <div className={cn('rounded-[9px] border', isOther ? 'border-accent bg-accent-soft' : 'border-hair bg-panel')}>
                        <button
                            type="button"
                            role="radio"
                            aria-checked={isOther}
                            disabled={isDisabled}
                            onClick={() => setIsOtherChosen(true)}
                            className="flex min-h-11 w-full cursor-pointer items-center gap-2.5 px-3 py-2.5 text-left text-[13px] disabled:cursor-not-allowed disabled:opacity-60 sm:min-h-0"
                            data-testid="repository-other"
                        >
                            <span className={cn('size-4 shrink-0 rounded-full border-[1.5px]', isOther ? 'border-[5px] border-accent' : 'border-muted')} aria-hidden />
                            Another repository
                        </button>
                        {isOther && (
                            <div className="px-3 pb-3">
                                <label htmlFor="repository-other-select" className="sr-only">Another repository</label>
                                <select
                                    id="repository-other-select"
                                    value={otherChoice}
                                    disabled={isDisabled}
                                    onChange={(event) => setOtherChoice(event.target.value)}
                                    className="h-11 w-full rounded-control border border-hair bg-panel px-2 font-mono text-[12.5px] text-body sm:h-9"
                                    data-testid="repository-other-select"
                                >
                                    <option value="">Choose a repository</option>
                                    {question.otherOptions.map((repo) => (
                                        <option key={repo} value={repo}>{repo}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>
                )}
            </div>
            {error !== null && (
                <p role="alert" id="repository-question-error" className="mt-3 rounded-control bg-fail-soft px-3 py-2 text-[13px]" data-testid="repository-question-error">{error}</p>
            )}
            <div className="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap [&>button]:max-sm:min-h-11 [&>button]:max-sm:w-full">
                <Button variant="primary" pending={isStarting} pendingLabel="Starting…" disabled={isDisabled || chosen === null} onClick={start} aria-describedby={error !== null ? 'repository-question-error' : undefined} data-testid="repository-start">
                    {chosen === null ? 'Pick a repository' : `Start in ${chosen}`}
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
