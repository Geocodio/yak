import { useForm } from '@inertiajs/react';
import { Button, Textarea, cn } from '@geocodio/console-ui';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { store as storeAnswers } from '@/routes/tasks/clarification-answers';
import type { QuestionData } from '@/types/tasks';

type Answer = { choices: string[]; other: string | null; otherOpen: boolean };

export function QuestionsForm({ taskId, questions }: { taskId: number; questions: QuestionData[] }) {
    const form = useForm<{ answers: Record<string, { choices: string[]; other: string | null }>; note: string }>({ answers: {}, note: '' });
    const [answers, setAnswers] = useState<Record<string, Answer>>(() =>
        Object.fromEntries(questions.map((question) => [question.id, { choices: [], other: null, otherOpen: false }])),
    );
    const errors = form.errors as Record<string, string | undefined>;

    const isAnswered = (answer: Answer) => answer.choices.length > 0 || (answer.otherOpen && (answer.other ?? '').trim() !== '');
    const answeredCount = questions.filter((question) => isAnswered(answers[question.id])).length;

    const toggle = (question: QuestionData, label: string) => {
        const current = answers[question.id];
        const has = current.choices.includes(label);
        const choices = question.multiSelect ? (has ? current.choices.filter((choice) => choice !== label) : [...current.choices, label]) : has ? [] : [label];
        setAnswers({ ...answers, [question.id]: { ...current, choices, otherOpen: question.multiSelect ? current.otherOpen : false } });
    };

    const toggleOther = (question: QuestionData) => {
        const current = answers[question.id];
        const otherOpen = !current.otherOpen;
        setAnswers({ ...answers, [question.id]: { ...current, otherOpen, choices: question.multiSelect || !otherOpen ? current.choices : [] } });
    };

    const submit = () => {
        form.transform((data) => ({
            answers: Object.fromEntries(
                questions.map((question) => {
                    const answer = answers[question.id];
                    return [question.id, { choices: answer.choices, other: answer.otherOpen ? answer.other : null }];
                }),
            ),
            note: data.note,
        }));
        form.post(storeAnswers.url(taskId), { preserveScroll: true });
    };

    const title = questions.length === 1 ? 'Yak has a question before it can continue' : `Yak has ${questions.length} questions before it can continue`;

    return (
        <section id="questions" aria-labelledby="questions-title" className="rounded-card border border-hair-strong bg-panel shadow-card" data-testid="questions-form">
            <div className="flex items-center justify-between gap-4 border-b border-hair px-6 py-4">
                <div>
                    <h2 id="questions-title" className="text-[15px] font-semibold">{title}</h2>
                    <p className="text-[12px] text-muted">Pick an answer, write your own, or skip a question.</p>
                </div>
                <span className="whitespace-nowrap text-[12px] text-muted">{answeredCount} of {questions.length} answered</span>
            </div>

            {errors.answers && <p role="alert" className="border-b border-hair px-6 py-3 text-[12px] text-fail" data-testid="questions-error">{errors.answers}</p>}

            {questions.map((question, index) => (
                <fieldset key={question.id} aria-labelledby={`question-${question.id}-label`} className="min-w-0 border-b border-hair px-6 py-5" data-testid={`question-${question.id}`}>
                    <div id={`question-${question.id}-label`} className="mb-3 flex flex-col gap-1.5">
                        <span className="flex items-center gap-2">
                            <span className="rounded-chip bg-accent-soft px-1.5 py-0.5 text-[11px] font-semibold text-accent-text">{question.header}</span>
                            <span className="text-[11px] text-muted">Question {index + 1} of {questions.length}</span>
                        </span>
                        <span className="text-[14px] font-semibold leading-snug">{question.question}</span>
                    </div>
                    <div className="flex flex-col gap-2">
                        {question.options.map((option) => (
                            <OptionRow
                                key={option.label}
                                label={option.label}
                                description={option.description}
                                selected={answers[question.id].choices.includes(option.label)}
                                multiSelect={question.multiSelect}
                                onClick={() => toggle(question, option.label)}
                            />
                        ))}
                        <OptionRow label="Other" description="Write your own answer." selected={answers[question.id].otherOpen} multiSelect={question.multiSelect} onClick={() => toggleOther(question)} />
                        {answers[question.id].otherOpen && (
                            <label className="flex flex-col gap-1.5 text-[12px] text-muted">
                                Your answer
                                <Textarea
                                    rows={2}
                                    maxLength={2000}
                                    value={answers[question.id].other ?? ''}
                                    onChange={(event) => setAnswers({ ...answers, [question.id]: { ...answers[question.id], other: event.target.value } })}
                                    placeholder="Tell Yak what you want instead…"
                                    data-testid={`question-${question.id}-other`}
                                />
                            </label>
                        )}
                        {(errors[`answers.${question.id}`] || errors[`answers.${question.id}.other`]) && (
                            <p role="alert" className="text-[12px] text-fail">{errors[`answers.${question.id}`] ?? errors[`answers.${question.id}.other`]}</p>
                        )}
                    </div>
                </fieldset>
            ))}

            <div className="flex flex-col gap-1.5 border-b border-hair px-6 py-5">
                <label htmlFor="questions-note" className="text-[13px] font-semibold">Anything else? <span className="font-normal text-muted">(optional)</span></label>
                <Textarea id="questions-note" rows={2} maxLength={2000} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} data-testid="questions-note" />
                {errors.note && <p role="alert" className="text-[12px] text-fail">{errors.note}</p>}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-4 rounded-b-card bg-app px-6 py-3.5">
                <span className="text-[12px] text-muted">Yak continues the task with your answers. Skipped questions are left to its judgment.</span>
                <Button variant="primary" onClick={submit} pending={form.processing} disabled={answeredCount === 0} data-testid="questions-submit">
                    Send answers
                </Button>
            </div>
        </section>
    );
}

function OptionRow({ label, description, selected, multiSelect, onClick }: { label: string; description: string; selected: boolean; multiSelect: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            role={multiSelect ? 'checkbox' : 'radio'}
            aria-checked={selected}
            onClick={onClick}
            className={cn(
                'flex min-h-11 w-full items-start gap-3 rounded-control border px-3 py-2.5 text-left',
                selected ? 'border-accent bg-accent-soft' : 'border-hair-strong bg-panel hover:border-accent',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'mt-0.5 flex size-4 flex-none items-center justify-center border',
                    multiSelect ? 'rounded-[4px]' : 'rounded-full',
                    selected ? 'border-accent bg-accent text-accent-ink' : 'border-hair-strong',
                )}
            >
                {selected && <Check className="size-3" strokeWidth={3} />}
            </span>
            <span className="flex flex-col gap-0.5">
                <span className="text-[13px] font-semibold">{label}</span>
                {description !== '' && <span className="text-[12px] leading-snug text-muted">{description}</span>}
            </span>
        </button>
    );
}
