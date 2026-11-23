import { useForm } from '@inertiajs/react';
import { Button, Textarea, cn } from '@geocodio/console-ui';
import { ArrowLeft, ArrowRight, Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { store as storeThreadAnswers } from '@/routes/threads/clarification-answers';
import { Prose } from '@/components/Prose';
import { QuestionAttachment } from '@/components/tasks/questions/QuestionAttachment';
import { visibleStep } from '@/components/tasks/questions/steps';
import type { QuestionAttachmentData, QuestionData, QuestionOptionData } from '@/types/tasks';

type Answer = { choices: string[]; other: string | null; otherOpen: boolean };

export function QuestionsForm({ threadId, questions, who = 'Yak', footer = 'Yak continues the task with your answers. Skipped questions are left to its judgment.', isLive = false }: { threadId: string; questions: QuestionData[]; who?: string; footer?: string; isLive?: boolean }) {
    const form = useForm<{ answers: Record<string, { choices: string[]; other: string | null }>; note: string }>({ answers: {}, note: '' });
    const [answers, setAnswers] = useState<Record<string, Answer>>(() =>
        Object.fromEntries(questions.map((question) => [question.id, { choices: [], other: null, otherOpen: false }])),
    );
    const [isDeciding, setIsDeciding] = useState(false);
    const errors = form.errors as Record<string, string | undefined>;
    const [chosenStep, setStep] = useState(0);
    const step = visibleStep(chosenStep, questions.length);
    const isStepped = questions.length > 1;

    // A validation error on a question that is not shown brings that question forward.
    useEffect(() => {
        const failing = questions.findIndex((question) => errors[`answers.${question.id}`] !== undefined || errors[`answers.${question.id}.other`] !== undefined);

        if (failing !== -1) {
            setStep(failing);
        }
    }, [form.errors]); // eslint-disable-line react-hooks/exhaustive-deps

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
        form.post(storeThreadAnswers.url(threadId), { preserveScroll: true });
    };

    // Claude is mid-turn and waiting: the person can hand the choice back instead of answering.
    const letClaudeDecide = () => {
        setIsDeciding(true);
        form.transform(() => ({ decide: true }));
        form.post(storeThreadAnswers.url(threadId), { preserveScroll: true, onFinish: () => setIsDeciding(false) });
    };

    const title = questions.length === 1 ? `${who} has a question before it can continue` : `${who} has ${questions.length} questions before it can continue`;

    return (
        <section id="card-question" aria-labelledby="questions-title" className="rounded-card border border-hair-strong bg-panel shadow-card" data-testid="questions-form">
            <div className="flex items-center justify-between gap-4 border-b border-hair px-6 py-4">
                <div>
                    {isLive ? (
                        <h2 id="questions-title" className="flex items-center gap-2 text-[14px] font-semibold" data-testid="questions-live">
                            <span aria-hidden className="size-2 flex-none rounded-full bg-warn" />
                            {who} is waiting for your answer · {questions.length} {questions.length === 1 ? 'question' : 'questions'}
                        </h2>
                    ) : (
                        <h2 id="questions-title" className="text-[15px] font-semibold">{title}</h2>
                    )}
                    <p className="text-[12px] text-muted">Pick an answer, write your own, or skip a question.</p>
                </div>
                <span className="whitespace-nowrap text-[12px] text-muted">{answeredCount} of {questions.length} answered</span>
            </div>

            {isStepped && (
                <nav aria-label="Questions" className="flex flex-wrap items-center gap-1.5 border-b border-hair px-6 py-3" data-testid="questions-stepper">
                    {questions.map((question, index) => {
                        const isCurrent = index === step;
                        const isDone = isAnswered(answers[question.id]);

                        return (
                            <button
                                key={question.id}
                                type="button"
                                onClick={() => setStep(index)}
                                aria-current={isCurrent ? 'step' : undefined}
                                className={cn(
                                    'inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-pill border px-2.5 text-[12px] font-medium',
                                    isCurrent ? 'border-accent bg-accent text-accent-ink' : 'border-hair-strong bg-panel text-body hover:border-accent',
                                )}
                                data-testid={`questions-step-${question.id}`}
                            >
                                {isDone ? <Check className="size-3.5" strokeWidth={2.5} aria-label="Answered" /> : <span className="tabular-nums opacity-70">{index + 1}</span>}
                                {question.header}
                            </button>
                        );
                    })}
                </nav>
            )}

            {errors.answers && <p role="alert" className="border-b border-hair px-6 py-3 text-[12px] text-fail" data-testid="questions-error">{errors.answers}</p>}

            {questions.map((question, index) => (
                <fieldset key={question.id} hidden={index !== step} aria-labelledby={`question-${question.id}-label`} className="@container min-w-0 border-b border-hair px-6 py-5" data-testid={`question-${question.id}`}>
                    <div id={`question-${question.id}-label`} className="mb-3 flex flex-col gap-1.5">
                        <span className="flex items-center gap-2">
                            <span className="rounded-chip bg-accent-soft px-1.5 py-0.5 text-[11px] font-semibold text-accent-text">{question.header}</span>
                            <span className="text-[11px] text-muted">Question {index + 1} of {questions.length}</span>
                        </span>
                        <span className="whitespace-pre-line text-[14px] font-semibold leading-snug">{question.question}</span>
                    </div>
                    {question.context.length > 0 && <QuestionContext attachments={question.context} />}
                    <div className="grid grid-cols-1 gap-2 @min-[620px]:grid-cols-2">
                        {question.options.map((option) => {
                            const selected = answers[question.id].choices.includes(option.label);
                            const row = <OptionRow label={option.label} description={option.description} selected={selected} multiSelect={question.multiSelect} onClick={() => toggle(question, option.label)} isCardHeader={hasAttachment(option)} />;

                            return hasAttachment(option) ? (
                                <div
                                    key={option.label}
                                    className={cn('flex min-w-0 flex-col gap-2.5 rounded-control border p-2', selected ? 'border-accent bg-accent-soft' : 'border-hair-strong bg-panel')}
                                    data-testid="question-option-card"
                                >
                                    {row}
                                    {option.attachments.map((attachment) => <QuestionAttachment key={attachment.id} attachment={attachment} size="option" />)}
                                    {option.previewHtml !== null && <Prose html={option.previewHtml} className="px-1 text-[12.5px]" />}
                                </div>
                            ) : (
                                <div key={option.label} className="col-span-full">{row}</div>
                            );
                        })}
                        <div className="col-span-full">
                            <OptionRow label="Other" description="Write your own answer." selected={answers[question.id].otherOpen} multiSelect={question.multiSelect} onClick={() => toggleOther(question)} />
                        </div>
                        {answers[question.id].otherOpen && (
                            <label className="flex flex-col gap-1.5 text-[12px] text-muted">
                                Your answer
                                <Textarea
                                    rows={2}
                                    maxLength={2000}
                                    value={answers[question.id].other ?? ''}
                                    onChange={(event) => setAnswers({ ...answers, [question.id]: { ...answers[question.id], other: event.target.value } })}
                                    placeholder={`Tell ${who} what you want instead…`}
                                    data-testid={`question-${question.id}-other`}
                                />
                            </label>
                        )}
                        {(errors[`answers.${question.id}`] || errors[`answers.${question.id}.other`]) && (
                            <p role="alert" className="text-[12px] text-fail">{errors[`answers.${question.id}`] ?? errors[`answers.${question.id}.other`]}</p>
                        )}
                    </div>
                    {isStepped && index === step && (
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
                            {index > 0 ? (
                                <Button variant="secondary" onClick={() => setStep(index - 1)} data-testid="questions-back">
                                    <ArrowLeft className="size-3.5" aria-hidden />
                                    Back
                                </Button>
                            ) : <span />}
                            {index < questions.length - 1 && (
                                <Button variant="secondary" onClick={() => setStep(index + 1)} data-testid="questions-next">
                                    Next: {questions[index + 1].header}
                                    <ArrowRight className="size-3.5" aria-hidden />
                                </Button>
                            )}
                        </div>
                    )}
                </fieldset>
            ))}

            {!isLive && <div className="flex flex-col gap-1.5 border-b border-hair px-6 py-5">
                <label htmlFor="questions-note" className="text-[13px] font-semibold">Anything else? <span className="font-normal text-muted">(optional)</span></label>
                <Textarea id="questions-note" rows={2} maxLength={2000} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} data-testid="questions-note" />
                {errors.note && <p role="alert" className="text-[12px] text-fail">{errors.note}</p>}
            </div>}

            <div className="flex flex-wrap items-center justify-between gap-4 rounded-b-card bg-app px-6 py-3.5">
                <span className="text-[12px] text-muted">{footer}</span>
                <div className="flex flex-wrap items-center gap-2">
                    {isLive && (
                        <Button variant="secondary" onClick={letClaudeDecide} pending={form.processing && isDeciding} disabled={form.processing} data-testid="questions-decide">
                            Let Claude decide
                        </Button>
                    )}
                    <Button variant="primary" onClick={submit} pending={form.processing && !isDeciding} disabled={answeredCount === 0 || form.processing} data-testid="questions-submit">
                        Send answers
                    </Button>
                </div>
            </div>
        </section>
    );
}

const hasAttachment = (option: QuestionOptionData): boolean => option.attachments.length > 0 || option.previewHtml !== null;

/** What Claude attached to the whole question, open at rest so it reads before the options. */
function QuestionContext({ attachments }: { attachments: QuestionAttachmentData[] }) {
    const [isShown, setIsShown] = useState(true);

    return (
        <div className="mb-4 flex flex-col gap-2" data-testid="question-context">
            <div className="flex items-center justify-between gap-3">
                <span className="text-[11px] font-semibold uppercase tracking-[0.04em] text-muted">Context from Claude · {attachments.length}</span>
                <button type="button" onClick={() => setIsShown(!isShown)} aria-expanded={isShown} className="cursor-pointer text-[12px] text-muted hover:text-body" data-testid="question-context-toggle">
                    {isShown ? 'Hide context' : 'Show context'}
                </button>
            </div>
            {isShown && (
                <div className={cn('grid grid-cols-1 gap-3', attachments.length > 1 && '@min-[520px]:grid-cols-2')}>
                    {attachments.map((attachment) => <QuestionAttachment key={attachment.id} attachment={attachment} size="context" />)}
                </div>
            )}
        </div>
    );
}

function OptionRow({ label, description, selected, multiSelect, onClick, isCardHeader = false }: { label: string; description: string; selected: boolean; multiSelect: boolean; onClick: () => void; isCardHeader?: boolean }) {
    return (
        <button
            type="button"
            role={multiSelect ? 'checkbox' : 'radio'}
            aria-checked={selected}
            onClick={onClick}
            className={cn(
                'flex min-h-11 w-full cursor-pointer items-start gap-3 rounded-control px-3 py-2.5 text-left',
                isCardHeader ? 'border-0 bg-transparent px-1.5 py-1' : selected ? 'border border-accent bg-accent-soft' : 'border border-hair-strong bg-panel hover:border-accent',
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
