import { cn } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { MessageWithAttachments } from '@/components/attachments/Attachments';
import { Prose } from '@/components/Prose';
import { YakAvatar } from '@/components/brand/YakAvatar';
import { YakBubble, type YakBubbleContext } from '@/components/tasks/YakBubble';
import type { MediaItem, ThreadEntryData } from '@/types/tasks';

/** A message in the conversation: a header with the avatar, the author and the time, then the body. */
function Entry({ who, meta, avatar, children, tone = 'plain' }: { who: string | null; meta: string; avatar: React.ReactNode; children: React.ReactNode; tone?: 'plain' | 'you' }) {
    return (
        <div className={cn(tone === 'you' && 'rounded-xl bg-panel-2 px-3.5 py-3')}>
            {who && (
                <div className="mb-2 flex min-w-0 items-center gap-2 text-[12.5px]">
                    {avatar}
                    <span className="font-semibold text-body">{who}</span>
                    <span className="tnum text-faint">{meta}</span>
                </div>
            )}
            <div className="text-[14px] leading-[1.55] wrap-break-word">{children}</div>
        </div>
    );
}

function PersonAvatar({ children }: { children: React.ReactNode }) {
    return <span className="grid size-[22px] flex-none place-items-center rounded-[7px] bg-hair text-[11px] font-bold text-body">{children}</span>;
}

function MediaGrid({ media, onOpen }: { media: MediaItem[]; onOpen: (media: MediaItem[], index: number) => void }) {
    if (media.length === 0) {
        return null;
    }
    return (
        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
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
    yak,
    focused,
    onOpenMedia,
    onSelectClarificationOption,
}: {
    entry: ThreadEntryData;
    /** What a Yak bubble needs from the page: the stream, the task and the actions. */
    yak: YakBubbleContext;
    /** True for the bubble of the run the page is focused on; it carries the activity stream. */
    focused: boolean;
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
            <Entry who={entry.who} meta={entry.meta} tone="you" avatar={<PersonAvatar><ExternalLink size={12} /></PersonAvatar>}>
                <div className="rounded-card border border-hair bg-panel p-4 shadow-card">
                    <Prose html={entry.bodyHtml} />
                </div>
            </Entry>
        );
    }

    if (entry.kind === 'user') {
        return (
            <Entry who={entry.who} meta={entry.meta} tone="you" avatar={<PersonAvatar>{entry.who?.[0]?.toUpperCase() ?? 'U'}</PersonAvatar>}>
                <MessageWithAttachments attachments={entry.attachments ?? []} onOpenMedia={onOpenMedia}>
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
                </MessageWithAttachments>
            </Entry>
        );
    }

    if (entry.kind === 'clarification-answers') {
        return (
            <div className="rounded-card border border-hair bg-panel shadow-card" data-testid="clarification-answers">
                <div className="flex items-center gap-2 border-b border-hair px-6 py-3 text-[12px] text-muted">
                    <span className="font-semibold text-body">{entry.who}</span>
                    <span>{entry.meta}</span>
                </div>
                <dl className="px-6 py-2">
                    {entry.answers?.map((item, index) => (
                        <div key={index} className={cn('grid grid-cols-[minmax(0,160px)_minmax(0,1fr)] gap-x-5 py-2', index > 0 && 'border-t border-hair')}>
                            <dt className="text-[12px] text-muted">{item.header}</dt>
                            <dd className="text-[13px]">
                                {item.skipped && <span className="text-muted">Skipped</span>}
                                {item.answer}
                                {item.other !== null && (
                                    <span className={cn(item.answer !== null && 'block')}>
                                        <span className="text-muted">Other: </span>
                                        {item.other}
                                    </span>
                                )}
                            </dd>
                        </div>
                    ))}
                </dl>
                {entry.note && (
                    <p className="border-t border-hair px-6 py-3 text-[13px]">
                        <span className="text-muted">Note: </span>
                        {entry.note}
                    </p>
                )}
            </div>
        );
    }

    if (entry.kind === 'clarification') {
        return (
            <div className={cn('rounded-xl border border-hair bg-panel px-3 pt-3 pb-3.5 shadow-card', entry.superseded && 'opacity-60')} data-testid="yak-bubble">
                <div className="mb-2 flex min-w-0 items-center gap-2 text-[12.5px]">
                    <YakAvatar />
                    <b className="font-semibold">Yak</b>
                    <span className="tnum text-faint">{entry.meta}</span>
                </div>
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
        );
    }

    // kind === 'yak'
    return (
        <>
            <YakBubble entry={entry} context={yak} focused={focused} />
            {entry.media && <MediaGrid media={entry.media} onOpen={onOpenMedia} />}
        </>
    );
}
