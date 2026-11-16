import { Head, Link } from '@inertiajs/react';
import { Button, cn } from '@geocodio/console-ui';
import { Check } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { AccountDialog } from '@/components/accounts/AccountDialog';
import { YakMark } from '@/components/brand/YakMark';
import { StartScreen } from '@/components/start/StartScreen';
import { AppLayout } from '@/layouts/AppLayout';
import type { WelcomePageData } from '@/types/home';
import type { PageProps } from '@/types/shared';

type Step = {
    key: string;
    title: string;
    body: string;
    isDone: boolean;
    isOptional?: boolean;
    actionLabel: string;
    href?: string;
    onAction?: () => void;
};

export default function Welcome({ welcome, repos, picker, defaultRepo, auth, setup }: PageProps<WelcomePageData>) {
    const [isAccountDialogOpen, setIsAccountDialogOpen] = useState(false);
    const accountMode = auth.user?.isAdmin === true && setup?.needsYakAccount === true ? 'addSystem' : 'add';

    const steps: Step[] = [
        {
            key: 'repository',
            title: 'Add a repository',
            body: 'The code Yak works on. It clones it into a sandbox.',
            isDone: welcome.hasRepository,
            actionLabel: 'Add repository',
            href: welcome.addRepositoryUrl,
        },
        {
            key: 'claude-account',
            title: 'Add a Claude account',
            body: 'The account a thread runs on.',
            isDone: welcome.hasClaudeAccount,
            actionLabel: 'Add Claude account',
            onAction: () => setIsAccountDialogOpen(true),
        },
        {
            key: 'channel',
            title: 'Connect a channel',
            body: 'Slack, Linear, Sentry or email can start threads too.',
            isDone: welcome.hasChannel,
            isOptional: true,
            actionLabel: 'Connect channel',
            href: welcome.channelsUrl,
        },
    ];
    const firstUndoneRequired = steps.find((step) => !step.isDone && step.isOptional !== true)?.key;

    if (welcome.hasRepository && welcome.hasClaudeAccount) {
        return (
            <>
                <Head title="Welcome" />
                <StartScreen testId="welcome-page" repos={repos} picker={picker} defaultRepo={defaultRepo} reloadOnly={['repos', 'picker', 'defaultRepo', 'welcome']} />
            </>
        );
    }

    return (
        <>
            <Head title="Welcome" />
            <div className="mx-auto w-full max-w-[640px] px-4 py-10 sm:px-6 sm:py-14" data-testid="welcome-page">
                <YakMark className="mb-3 size-12 drop-shadow-[0_6px_10px_rgba(26,26,31,0.14)]" />
                <h1 className="text-[22px] font-semibold tracking-[-0.015em] text-body">Welcome to Yak</h1>
                <p className="mt-2 text-[14px] text-muted">
                    Two steps and you can start your first thread. Yak works in its own sandbox, serves a preview, and opens a PR for you to review.
                </p>

                <ul className="mt-6 divide-y divide-hair rounded-card border border-hair bg-panel" data-testid="welcome-checklist">
                    {steps.map((step) => (
                        <li key={step.key} className="flex flex-wrap items-center gap-3 px-4 py-3.5" data-testid={`welcome-step-${step.key}`} data-done={step.isDone}>
                            <span
                                className={cn(
                                    'flex size-5 shrink-0 items-center justify-center rounded-pill',
                                    step.isDone ? 'bg-ok/20 text-ok' : 'border border-hair-strong',
                                )}
                            >
                                {step.isDone && <Check size={13} aria-hidden="true" />}
                            </span>
                            <div className="min-w-[180px] flex-1">
                                <p className="text-[13.5px] font-medium text-body">
                                    {step.title}
                                    {step.isOptional === true && <span className="ml-2 text-[12px] font-normal text-faint">Optional</span>}
                                </p>
                                <p className="mt-0.5 text-[12.5px] text-muted">{step.body}</p>
                            </div>
                            {!step.isDone &&
                                (step.href !== undefined ? (
                                    <Link href={step.href} data-testid={`welcome-action-${step.key}`}>
                                        <Button variant={step.key === firstUndoneRequired ? 'primary' : 'secondary'} tabIndex={-1}>
                                            {step.actionLabel}
                                        </Button>
                                    </Link>
                                ) : (
                                    <Button
                                        variant={step.key === firstUndoneRequired ? 'primary' : 'secondary'}
                                        onClick={step.onAction}
                                        data-testid={`welcome-action-${step.key}`}
                                    >
                                        {step.actionLabel}
                                    </Button>
                                ))}
                        </li>
                    ))}
                </ul>

                <div className="mt-8" data-testid="welcome-start-thread">
                    <p className="rounded-card border border-dashed border-hair px-4 py-5 text-center text-[13px] text-muted" data-testid="welcome-start-locked">
                        Finish the steps above and you can start your first thread here.
                    </p>
                </div>

                <p className="mt-8 text-[12.5px] text-muted">
                    Threads and tasks: a thread is one piece of work with its own sandbox, conversation and preview. Each request in it, from you or from Slack, Linear or Sentry, runs as a task.
                </p>
            </div>

            {isAccountDialogOpen && setup !== null && (
                <AccountDialog mode={accountMode} models={setup.models} hasDefault={setup.hasOwnAccount} onClose={() => setIsAccountDialogOpen(false)} />
            )}
        </>
    );
}

Welcome.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
