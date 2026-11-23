import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronUp, GitFork } from 'lucide-react';
import { useId, useState } from 'react';
import { Prose } from '@/components/Prose';
import { ChildStateDot } from '@/components/threads/stack/ChildStateDot';
import { show } from '@/routes/threads';
import type { ConversationItem } from './conversationItem';

/**
 * A sub-thread's result (or failure) where it reached this thread. Collapsed by default so Claude's reply right after
 * it is what gets read; a failure opens by itself.
 */
export function ChildResultRow({ item }: { item: ConversationItem }) {
    const result = item.childResult;
    const bodyId = useId();
    const [isOpen, setIsOpen] = useState(result?.state === 'failed');

    if (!result) {
        return null;
    }

    const isFailed = result.state === 'failed';
    const time = new Date(result.at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    return (
        <div className="ml-9 overflow-hidden rounded-card border border-hair bg-panel text-[13px]" data-testid={`child-result-${result.id}`} data-state={result.state} data-open={isOpen}>
            <div className="flex items-center gap-2.5 px-3 py-2.5">
                {isFailed ? <ChildStateDot state="failed" /> : <GitFork size={16} aria-hidden className="shrink-0 text-muted" />}
                <span className="min-w-0 flex-1 text-body">
                    <span className="font-medium" data-testid="child-result-title">{isFailed ? `Failed: ${result.reason ?? 'the sub-thread did not finish'}` : `Result from ${result.title}`}</span>{' '}
                    <span className="text-muted">· {isFailed ? `${result.title} · ` : ''}{result.kind} · {time}</span>
                </span>
                <Link href={show.url(result.childThreadId)} className="shrink-0 text-accent-text hover:underline" data-testid="child-result-open">Open sub-thread</Link>
                <button
                    type="button"
                    aria-label={isOpen ? 'Hide the result' : 'Show the result'}
                    aria-expanded={isOpen}
                    aria-controls={bodyId}
                    onClick={() => setIsOpen((value) => !value)}
                    className="grid size-[30px] shrink-0 cursor-pointer place-items-center rounded-[7px] text-muted hover:bg-panel-2 hover:text-body"
                    data-testid="child-result-toggle"
                >
                    {isOpen ? <ChevronUp size={16} aria-hidden /> : <ChevronDown size={16} aria-hidden />}
                </button>
            </div>
            {isOpen && (
                <div id={bodyId} className="border-t border-hair bg-panel-2 px-3.5 py-3" data-testid="child-result-body">
                    <Prose html={result.html} />
                </div>
            )}
        </div>
    );
}
