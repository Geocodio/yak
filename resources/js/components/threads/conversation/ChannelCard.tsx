import { ExternalLink, Mail, MessageSquare, Zap } from 'lucide-react';
import type { ReactNode } from 'react';
import type { ThreadChannelCard } from '@/types/threads';

const CHANNELS = {
    slack: { mark: MessageSquare, open: 'Open in Slack' },
    linear: { mark: Zap, open: 'Open in Linear' },
    email: { mark: Mail, open: null },
} as const;

function Context({ card }: { card: ThreadChannelCard }) {
    if (card.channel === 'slack') {
        return card.reference ? (
            <>
                Thread in <strong className="font-medium text-body">{card.reference}</strong>
            </>
        ) : (
            <>Slack thread</>
        );
    }

    if (card.channel === 'linear') {
        return (
            <>
                {card.reference && <strong className="font-medium text-body">{card.reference}</strong>} {card.title}
            </>
        );
    }

    return card.reference ? (
        <>
            From <strong className="font-medium text-body">{card.reference}</strong>
        </>
    ) : (
        <>Email</>
    );
}

/** A message from Slack, Linear or email in a card: where it came from and a link back, then the message itself. */
export function ChannelCard({ card, children }: { card: ThreadChannelCard; children: ReactNode }) {
    const channel = CHANNELS[card.channel];
    const Mark = channel.mark;

    return (
        <div className="mt-1.5 overflow-hidden rounded-card border border-hair bg-panel" data-testid={`channel-card-${card.channel}`}>
            <div className="flex items-center gap-2 border-b border-hair bg-panel-2 px-2.5 py-1.5 text-[12px] text-muted">
                <Mark className="size-3 shrink-0" aria-hidden />
                <span className="min-w-0 flex-1 truncate">
                    <Context card={card} />
                </span>
                {card.url && channel.open && (
                    <a href={card.url} target="_blank" rel="noreferrer" className="inline-flex shrink-0 items-center gap-1 text-accent-text hover:underline" data-testid="channel-card-link">
                        {channel.open}
                        <ExternalLink className="size-3" aria-hidden />
                    </a>
                )}
            </div>
            <div className="px-2.5 py-2">
                {card.channel === 'email' && card.title && <div className="mb-1 text-[14px] font-semibold text-body">{card.title}</div>}
                {children}
            </div>
        </div>
    );
}
