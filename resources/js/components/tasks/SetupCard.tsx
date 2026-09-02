import { router } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { Check, ExternalLink, Rocket, X } from 'lucide-react';
import { dismiss } from '@/routes/tasks/setup-card';
import type { SetupCard as SetupCardData } from '@/types/tasks';

export function SetupCard({ card }: { card: SetupCardData }) {
    if (card === null) {
        return null;
    }

    return (
        <div
            data-testid="setup-card"
            className="m-5 mb-0 rounded-card border border-warn/30 bg-gradient-to-br from-warn/5 to-panel p-5"
        >
            <div className="flex items-start gap-4">
                <div className="shrink-0 rounded-pill bg-warn/15 p-2">
                    <Rocket size={20} className="text-warn" />
                </div>
                <div className="flex-1">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-[12px] font-semibold uppercase tracking-wider text-warn">Getting started with Yak</h2>
                            <p className="mt-1 text-[13px] text-muted">Three small steps and you're ready to ship papercuts.</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => router.post(dismiss.url(), {}, { preserveScroll: true })}
                            aria-label="Dismiss"
                            data-testid="dismiss-setup-card"
                            className="shrink-0 text-faint hover:text-body"
                        >
                            <X size={16} />
                        </button>
                    </div>
                    <ul className="mt-4 space-y-3">
                        {card.items.map((item) => (
                            <li key={item.title} className="flex items-start gap-3" data-testid="setup-step">
                                {item.done ? (
                                    <div className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-pill bg-ok/20 text-ok">
                                        <Check size={13} />
                                    </div>
                                ) : (
                                    <div className="mt-0.5 size-5 shrink-0 rounded-pill border border-hair-strong" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <a
                                        href={item.url}
                                        target={item.external ? '_blank' : undefined}
                                        rel={item.external ? 'noopener noreferrer' : undefined}
                                        className={cn(
                                            'text-[13px] font-medium text-body hover:text-accent-text',
                                            item.done && 'line-through decoration-hair-strong',
                                        )}
                                    >
                                        {item.title}
                                        {item.external && <ExternalLink size={11} className="ml-1 inline opacity-60" />}
                                    </a>
                                    <p className="mt-0.5 text-[12px] text-muted">{item.body}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
}
