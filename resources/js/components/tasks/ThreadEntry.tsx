import { cn } from '@geocodio/console-ui';
import { Check, ExternalLink } from 'lucide-react';
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
                {entry.answers?.map((item, index) => (
                    <div key={index} className={cn('flex flex-col gap-2 px-6 py-3.5', index > 0 && 'border-t border-hair')} data-testid="clarification-answer">
                        <p className="m-0 max-w-[65ch] text-[13px] leading-normal text-muted">{item.question}</p>
                        <div className="flex items-start gap-2.5 rounded-lg bg-panel-2 px-3 py-2.5">
                            {item.skipped ? (
                                <span className="text-[13px] text-muted">Skipped</span>
                            ) : (
                                <>
                                    <Check size={15} className="mt-0.5 shrink-0 text-ok" aria-hidden />
                                    <div className="flex min-w-0 flex-col gap-1.5">
                                        {item.choices.map((choice) => (
                                            <div key={choice.label}>
                                                <div className="text-[13.5px] font-semibold">{choice.label}</div>
                                                {choice.description && <div className="text-[12.5px] leading-normal text-muted">{choice.description}</div>}
                                            </div>
                                        ))}
                                        {item.other !== null && (
                                            <div className="text-[13.5px] wrap-break-word">
                                                <span className="text-muted">Other: </span>
                                                {item.other}
                                            </div>
                                        )}
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                ))}
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
