import { Check, Circle, Loader2, X } from 'lucide-react';
import type { ReactNode } from 'react';
import type { RepositoryCardData } from '@/types/thread-events';

type StepStatus = 'pending' | 'running' | 'ok' | 'failed';

function StepIcon({ status }: { status: StepStatus }) {
    if (status === 'ok') {
        return <Check aria-hidden className="size-3.5 text-ok" />;
    }

    if (status === 'failed') {
        return <X aria-hidden className="size-3.5 text-fail" />;
    }

    if (status === 'running') {
        return <Loader2 aria-hidden className="size-3.5 animate-spin text-brand" />;
    }

    return <Circle aria-hidden className="size-3.5 text-muted" />;
}

function Step({ status, label, detail }: { status: StepStatus; label: ReactNode; detail?: string | null }) {
    return (
        <li className="flex items-center gap-2" data-status={status}>
            <StepIcon status={status} />
            <span className="text-body">{label}</span>
            {detail && <span className="ml-auto text-[12px] text-muted">{detail}</span>}
        </li>
    );
}

const seconds = (value: number | null): string | null => (value === null ? null : `${value}s`);

/** The clone and setup steps of an added repository, with the setup output folded away. */
export function RepositoryCardSteps({ card }: { card: RepositoryCardData }) {
    if (card.state === 'no_access') {
        return null;
    }

    const cloneStatus: StepStatus = card.state === 'running' ? 'running' : 'ok';
    const setupStatus: StepStatus = { running: 'pending', setup: 'running', done: 'ok', setup_failed: 'failed' }[card.state] as StepStatus;

    return (
        <div className="mt-2 text-[12.5px]" data-testid="repository-card-steps">
            <ul className="flex flex-col gap-1.5">
                <Step status={cloneStatus} label={`Clone ${card.repo}`} detail={seconds(card.cloneSeconds)} />
                <Step status={setupStatus} label={<code className="font-mono text-[12px]">Run .yak/setup.sh</code>} detail={card.state === 'setup_failed' ? 'Exit code 1' : seconds(card.setupSeconds)} />
            </ul>
            {card.state === 'done' && card.setupSeconds !== null && <p className="mt-1 text-[12px] text-muted">Ready in {card.setupSeconds}s</p>}
            {card.state === 'setup_failed' && card.output && (
                <details className="mt-2">
                    <summary className="cursor-pointer text-[12px] text-muted">Setup output</summary>
                    <pre className="mt-1 max-h-40 overflow-auto rounded-card bg-panel-2 p-2 font-mono text-[11px] text-body" data-testid="repository-card-output">
                        {card.output}
                    </pre>
                </details>
            )}
        </div>
    );
}
