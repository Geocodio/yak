import { cn } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { Prose } from '@/components/Prose';
import { FindingsBlock } from '@/components/tasks/FindingsBlock';
import type { FindingsData, MediaItem, ThreadEntryData } from '@/types/tasks';

function Entry({ who, meta, avatar, children }: { who: string | null; meta: string; avatar: React.ReactNode; children: React.ReactNode }) {
    return (
        <div className="flex gap-3">
            <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-panel-2 text-[11px] font-medium">{avatar}</div>
            <div className="min-w-0 flex-1">
                {who && (
                    <div className="flex items-baseline gap-2 text-[12px]">
                        <span className="font-medium text-body">{who}</span>
                        <span className="text-faint">{meta}</span>
                    </div>
                )}
                <div className="mt-1 text-[13px] leading-relaxed">{children}</div>
            </div>
        </div>
    );
}

function MediaGrid({ media, onOpen }: { media: MediaItem[]; onOpen: (media: MediaItem[], index: number) => void }) {
    if (media.length === 0) {
        return null;
    }
    return (
        <div className="mt-3 grid grid-cols-3 gap-2">
            {media.map((item, index) => (
                <button
                    key={item.id}
                    type="button"
                    onClick={() => onOpen(media, index)}
                    className="group overflow-hidden rounded-card border border-hair bg-panel text-left shadow-card hover:border-hair-strong"
                    data-testid={`media-thumb-${item.id}`}
                >
                    {item.kind === 'video' ? (
                        <video muted preload="metadata" className="aspect-video w-full bg-panel-2 object-cover" src={item.url} />
                    ) : (
                        <img src={item.thumbUrl ?? item.url} alt={item.caption ?? ''} loading="lazy" className="aspect-video w-full object-cover" />
                    )}
                    {item.caption && <div className="truncate px-2 py-1.5 text-[11px] text-muted">{item.caption}</div>}
                </button>
            ))}
        </div>
    );
}

export function ThreadEntry({
    entry,
    findings,
    onOpenMedia,
    onSelectClarificationOption,
}: {
    entry: ThreadEntryData;
    findings: FindingsData;
    onOpenMedia: (media: MediaItem[], index: number) => void;
    onSelectClarificationOption: (option: string) => void;
}) {
    const [expanded, setExpanded] = useState(false);

    if (entry.kind === 'system') {
        return (
            <div className="flex items-center justify-center gap-2 text-[12px] text-faint">
                <span>{entry.bodyHtml.replace(/<[^>]+>/g, '')}</span>
                <span>· {entry.meta}</span>
            </div>
        );
    }

    if (entry.kind === 'review-context') {
        return (
            <Entry who={entry.who} meta={entry.meta} avatar={<ExternalLink size={12} />}>
                <div className="rounded-card border border-hair bg-panel p-4 shadow-card">
                    <Prose html={entry.bodyHtml} />
                </div>
            </Entry>
        );
    }

    if (entry.kind === 'user') {
        return (
            <Entry who={entry.who} meta={entry.meta} avatar={entry.who?.[0]?.toUpperCase() ?? 'U'}>
                {entry.fullText && !expanded ? (
                    <div>
                        <p>{entry.fullText}</p>
                        <button type="button" onClick={() => setExpanded(true)} className="mt-1 text-[12px] text-accent-text hover:underline">
                            full request ▸
                        </button>
                    </div>
                ) : (
                    <Prose html={entry.bodyHtml} />
                )}
            </Entry>
        );
    }

    if (entry.kind === 'clarification') {
        return (
            <Entry who="Yak" meta={entry.meta} avatar={<span className="text-accent-text">Y</span>}>
                <div className={cn('rounded-card border border-hair bg-panel p-4 shadow-card', entry.superseded && 'opacity-60')}>
                    <Prose html={entry.bodyHtml} />
                    {!entry.superseded && entry.options && entry.options.length > 0 && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {entry.options.map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    onClick={() => onSelectClarificationOption(option)}
                                    className="rounded-pill border border-hair bg-panel-2 px-2.5 py-1 text-[12px] text-body hover:border-hair-strong"
                                    data-testid="clarification-option"
                                >
                                    {option}
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </Entry>
        );
    }

    // kind === 'yak'
    return (
        <Entry who="Yak" meta={entry.meta} avatar={<span className="text-accent-text">Y</span>}>
            <div className={cn('rounded-card border border-hair bg-panel p-4 shadow-card', entry.superseded && 'opacity-60')}>
                {entry.live ? (
                    <div className="flex items-center gap-2 text-muted">
                        <span className="relative flex h-2 w-2">
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-pill bg-info opacity-40" />
                            <span className="relative inline-flex h-2 w-2 rounded-pill bg-info" />
                        </span>
                        Working…
                    </div>
                ) : (
                    <Prose html={entry.bodyHtml} />
                )}
                {entry.error && <p className="mt-2 text-[12px] text-fail">{entry.error}</p>}
                {findings && <FindingsBlock findings={findings} />}
                {entry.links && entry.links.length > 0 && (
                    <div className="mt-3 flex flex-wrap items-center gap-3 border-t border-hair pt-3 text-[12px]">
                        {entry.links.map((link) =>
                            link.label === 'View research artifact' ? (
                                <a key={link.url} href={link.url} className="text-accent-text hover:underline" data-testid="research-link">
                                    {link.label}
                                </a>
                            ) : (
                                <a
                                    key={link.url}
                                    href={link.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-1 text-accent-text hover:underline"
                                    data-testid="pr-link"
                                >
                                    <ExternalLink size={12} /> {link.label}
                                </a>
                            ),
                        )}
                    </div>
                )}
            </div>
            {entry.media && <MediaGrid media={entry.media} onOpen={onOpenMedia} />}
        </Entry>
    );
}
