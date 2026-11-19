import { router } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { store } from '@/routes/threads/repository-answer';
import type { RepositoryQuestion } from '@/types/threads';

/** "Which repository is this for?": the option chips of the old clarification, and a Start button for the chosen one. */
export function RepositoryQuestionCard({ threadId, question }: { threadId: string; question: RepositoryQuestion }) {
    const [selected, setSelected] = useState<string | null>(question.selected);
    useEffect(() => setSelected(question.selected), [question.selected]);
    const [isStarting, setIsStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const start = () => {
        if (selected === null) {
            return;
        }
        setError(null);
        setIsStarting(true);
        router.post(store.url(threadId), { repo: selected }, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Yak could not start in that repository. Try again.'),
            onFinish: () => setIsStarting(false),
        });
    };

    return (
        <section className="rounded-card border border-hair bg-panel px-4 py-4" aria-labelledby="repository-question-title" data-testid="card-repository-question">
            <h2 id="repository-question-title" className="text-[15px] font-semibold">{question.prompt}</h2>
            <div className="mt-3 flex flex-wrap gap-2" role="radiogroup" aria-labelledby="repository-question-title">
                {question.options.map((option) => {
                    const isSelected = option === selected;

                    return (
                        <button
                            key={option}
                            type="button"
                            role="radio"
                            aria-checked={isSelected}
                            disabled={!question.canAnswer || isStarting}
                            onClick={() => setSelected(option)}
                            className={`inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-pill border px-3 text-[13px] font-medium disabled:cursor-not-allowed disabled:opacity-60 ${isSelected ? 'border-accent bg-accent-soft text-accent-text' : 'border-hair bg-panel text-body hover:bg-panel-2'}`}
                            data-testid="repository-option"
                        >
                            {isSelected && <Check className="size-3.5" aria-hidden />}
                            {option}
                        </button>
                    );
                })}
            </div>
            {error !== null && (
                <p role="alert" id="repository-question-error" className="mt-3 rounded-control bg-fail-soft px-3 py-2 text-[13px]" data-testid="repository-question-error">{error}</p>
            )}
            {question.canAnswer ? (
                <div className="mt-4 flex justify-end">
                    <Button variant="primary" pending={isStarting} pendingLabel="Starting…" disabled={selected === null || isStarting} onClick={start} aria-describedby={error !== null ? 'repository-question-error' : undefined} data-testid="repository-start">
                        {selected === null ? 'Pick a repository' : `Start in ${selected}`}
                    </Button>
                </div>
            ) : (
                <p className="mt-3 text-[12.5px] text-muted">Only the person who owns this thread can pick the repository.</p>
            )}
        </section>
    );
}
