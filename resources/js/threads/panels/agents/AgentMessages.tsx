import { Button, TextInput } from '@geocodio/console-ui';
import { useState, type FormEvent } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { formatWhen } from '@/lib/format';
import { store } from '@/routes/threads/agents/messages';
import type { ThreadAgentMessage } from '@/types/thread-agents';
import { isRunning, type AgentRowData } from './agentsState';

type SentMessage = ThreadAgentMessage & { isLocal: boolean };

const MAX_LENGTH = 5000;

/** What the agent was told: the page's messages, then any sent since the page was read that it does not list yet. */
function sentMessages(agent: AgentRowData, local: ThreadAgentMessage[]): SentMessage[] {
    const known = new Set(agent.messages.map((message) => message.id));

    return [...agent.messages.map((message) => ({ ...message, isLocal: false })), ...local.filter((message) => !known.has(message.id)).map((message) => ({ ...message, isLocal: true }))];
}

/** The messages sent to one agent, each with whether Claude has passed it on yet. */
export function AgentMessageList({ agent, local }: { agent: AgentRowData; local: ThreadAgentMessage[] }) {
    const messages = sentMessages(agent, local);

    if (messages.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col gap-2" aria-label="Messages sent to this agent" data-testid="agent-messages">
            {messages.map((message) => (
                <li key={message.id} className="rounded-card border border-hair bg-panel-2 px-3 py-2 text-[13px]" data-testid="agent-message" data-forwarded={message.forwardedAt !== null}>
                    <p className="whitespace-pre-wrap break-words text-body">{message.text}</p>
                    <p className="mt-1 text-[12px] text-muted">
                        {message.authorName ?? 'You'} · {formatWhen(message.sentAt)} ·{' '}
                        {message.forwardedAt === null ? <span data-testid="agent-message-waiting">Waiting for Claude to pass it on</span> : <span data-testid="agent-message-passed">Claude passed it on</span>}
                    </p>
                </li>
            ))}
        </ul>
    );
}

/** Sends a message to one agent through Claude. The box stays disabled while it sends and says so when it fails. */
export function AgentMessageBox({ threadId, agent, onSent }: { threadId: string; agent: AgentRowData; onSent: (message: ThreadAgentMessage) => void }) {
    const [text, setText] = useState('');
    const [isSending, setIsSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!isRunning(agent)) {
        return (
            <p className="border-t border-hair px-5 py-3 text-[12px] text-muted" data-testid="agent-message-closed">
                This agent has ended, so it cannot take a message.
            </p>
        );
    }

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const message = text.trim();

        if (message === '' || isSending) {
            return;
        }

        if (message.length > MAX_LENGTH) {
            setError('The message must not be longer than 5,000 characters.');

            return;
        }

        setIsSending(true);
        setError(null);

        try {
            const response = await fetch(store.url({ thread: threadId, agent: agent.id }), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ message }),
            });
            const body = (await response.json().catch(() => ({}))) as Partial<ThreadAgentMessage> & { message?: string };

            if (!response.ok) {
                setError(body.message ?? 'The message was not sent. Try again.');

                return;
            }

            onSent({ id: body.id ?? Date.now(), text: body.text ?? message, authorName: null, sentAt: body.sentAt ?? new Date().toISOString(), forwardedAt: body.forwardedAt ?? null });
            setText('');
        } catch {
            setError('The message was not sent. Check the connection and try again.');
        } finally {
            setIsSending(false);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-1.5 border-t border-hair px-5 pb-4 pt-3" data-testid="agent-message-form">
            <label htmlFor={`agent-message-${agent.id}`} className="text-[12px] text-muted">
                Message this agent (Claude passes it on)
            </label>
            <div className="flex gap-2">
                <TextInput
                    id={`agent-message-${agent.id}`}
                    type="text"
                    value={text}
                    onChange={(event) => setText(event.target.value)}
                    disabled={isSending}
                    aria-invalid={error !== null || undefined}
                    aria-describedby={error === null ? undefined : `agent-message-error-${agent.id}`}
                    className="min-w-0 flex-1"
                    data-testid="agent-message-input"
                />
                <Button type="submit" variant="primary" className="h-9" pending={isSending} disabled={text.trim() === ''} data-testid="agent-message-send">
                    Send
                </Button>
            </div>
            {error !== null && (
                <p id={`agent-message-error-${agent.id}`} role="alert" className="text-[12px] text-fail" data-testid="agent-message-error">
                    {error}
                </p>
            )}
        </form>
    );
}
