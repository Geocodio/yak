import { useState } from 'react';
import type { StackMessageRow } from '@/types/stack';

const COLLAPSED_COUNT = 3;

/** The newest messages, with the rest behind a toggle. */
export function StackMessages({ messages }: { messages: StackMessageRow[] }) {
    const [isShowingAll, setIsShowingAll] = useState(false);

    if (messages.length === 0) {
        return null;
    }

    return (
        <section data-testid="stack-messages">
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-body">Messages</h3>
                {messages.length > COLLAPSED_COUNT && (
                    <button type="button" onClick={() => setIsShowingAll((value) => !value)} className="cursor-pointer text-[12px] text-muted hover:text-body" data-testid="stack-messages-show-all">
                        {isShowingAll ? 'Show fewer' : `Show all ${messages.length}`}
                    </button>
                )}
            </div>
            <ul className="mt-2 divide-y divide-hair rounded-card border border-hair bg-panel">
                {(isShowingAll ? messages : messages.slice(-COLLAPSED_COUNT)).map((message) => (
                    <li key={message.id} className="px-3 py-2" data-testid={`stack-message-${message.id}`}>
                        <div className="flex items-baseline justify-between gap-2 text-[12px]">
                            <span className="min-w-0 truncate font-medium text-body">{message.fromName} <span className="text-muted">to {message.toName}</span></span>
                            <time className="shrink-0 text-muted" dateTime={message.createdAt}>{new Date(message.createdAt).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}</time>
                        </div>
                        <p className="mt-1 whitespace-pre-wrap text-[12.5px] text-muted">{message.text}</p>
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-[11.5px] text-muted">Messages come from threads, not people. A receiving thread is told so, and cannot approve anything on your behalf.</p>
        </section>
    );
}
