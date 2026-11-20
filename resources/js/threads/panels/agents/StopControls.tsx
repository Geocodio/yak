import { router } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { useEffect, useRef, useState } from 'react';
import { stop, stopAll } from '@/routes/threads/agents';
import type { ThreadAgent } from '@/types/thread-agents';
import { isRunning } from './agentsState';

/** Stops one agent and the agents it started. Once the stop is asked for it reads "Stopping…" until the agent ends. */
export function StopAgentButton({ threadId, agent, label = 'Stop', className }: { threadId: string; agent: ThreadAgent; label?: string; className?: string }) {
    const [isPending, setIsPending] = useState(false);

    if (!isRunning(agent)) {
        return null;
    }

    const isStopping = isPending || agent.stopRequestedAt !== null;

    return (
        <Button
            variant="secondary"
            className={className ?? 'h-8'}
            disabled={isStopping}
            onClick={() => {
                setIsPending(true);
                router.post(stop.url({ thread: threadId, agent: agent.id }), {}, { preserveScroll: true, preserveState: true, onFinish: () => setIsPending(false) });
            }}
            data-testid="agent-stop"
        >
            {isStopping ? 'Stopping…' : label}
        </Button>
    );
}

/** Stops every running agent. The first click asks inline; nothing stops until the person confirms. */
export function StopAllControl({ threadId, runningCount, className }: { threadId: string; runningCount: number; className?: string }) {
    const [isConfirming, setIsConfirming] = useState(false);
    const [isPending, setIsPending] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const confirm = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (isConfirming) {
            confirm.current?.focus();
        }
    }, [isConfirming]);

    // Nothing is left to stop once the last agent ended.
    useEffect(() => {
        if (runningCount === 0) {
            setIsConfirming(false);
        }
    }, [runningCount]);

    const cancel = () => {
        setIsConfirming(false);
        trigger.current?.focus();
    };

    if (isConfirming) {
        return (
            <span
                role="group"
                aria-label="Confirm stopping everything"
                className="inline-flex items-center gap-1.5"
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        event.stopPropagation();
                        cancel();
                    }
                }}
                data-testid="agents-stop-all-confirm"
            >
                <span className="text-[12px] text-muted">Stop {runningCount === 1 ? '1 running agent' : `all ${runningCount}`}?</span>
                <Button
                    ref={confirm}
                    variant="primary"
                    className="h-7"
                    pending={isPending}
                    onClick={() => {
                        setIsPending(true);
                        router.post(stopAll.url({ thread: threadId }), {}, { preserveScroll: true, preserveState: true, onFinish: () => { setIsPending(false); setIsConfirming(false); } });
                    }}
                    data-testid="agents-stop-all-confirmed"
                >
                    Stop
                </Button>
                <Button variant="tertiary" className="h-7" onClick={cancel} data-testid="agents-stop-all-cancel">
                    Keep running
                </Button>
            </span>
        );
    }

    return (
        <Button ref={trigger} variant="secondary" className={className ?? 'h-7'} onClick={() => setIsConfirming(true)} data-testid="agents-stop-all">
            Stop all
        </Button>
    );
}
