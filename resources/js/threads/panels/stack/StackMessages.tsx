import type { StackMessageRow } from '@/types/stack';

export function StackMessages({ messages }: { messages: StackMessageRow[] }) {
    if (messages.length === 0) {
        return null;
    }

    return (
        <section data-testid="stack-messages">
            <h3 className="text-[13px] font-semibold text-body">Messages</h3>
            <ul className="mt-2 divide-y divide-hair rounded-card border border-hair bg-panel">
                {messages.map((message) => (
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
