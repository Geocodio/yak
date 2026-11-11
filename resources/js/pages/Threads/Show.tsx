import { Head } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { useState, type ReactNode } from 'react';
import { TeleportConversationNotices } from '@/components/threads/teleport/TeleportConversationNotices';
import { AppLayout } from '@/layouts/AppLayout';
import { sidebarPanelsFor } from '@/threads/panels';
import '@/threads/panels/index';
import type { PageProps } from '@/types/shared';
import type { ThreadPageData } from '@/types/threads';

type Props = PageProps<ThreadPageData>;

export default function Show({ thread, canDrive, teleport }: Props) {
    const panels = sidebarPanelsFor(thread);
    const [activeId, setActiveId] = useState<string | null>(null);
    const active = panels.find((panel) => panel.id === activeId) ?? panels[0] ?? null;

    return (
        <>
            <Head title={thread.title} />

            <header className="shrink-0 border-b border-hair px-4 py-3 sm:px-8" data-testid="thread-header">
                <h1 className="text-[15px] font-semibold text-body">{thread.title}</h1>
                <p className="text-[12px] text-muted">{thread.repo}</p>
            </header>

            <div className="flex min-h-0 flex-1">
                <div className="flex min-w-0 flex-1 flex-col" data-testid="thread-conversation">
                    {/* The conversation, composer and notices (W2-THREADPAGE) mount here. */}
                    <TeleportConversationNotices thread={thread} teleport={teleport} />
                    <div className="min-h-0 flex-1 overflow-auto" />
                </div>

                {panels.length > 0 && active !== null && (
                    <aside className="hidden w-[320px] shrink-0 flex-col border-l border-hair bg-sidebar lg:flex" data-testid="thread-sidebar">
                        <div role="tablist" aria-label="Thread panels" className="flex shrink-0 gap-4 border-b border-hair px-4">
                            {panels.map((panel) => (
                                <button
                                    key={panel.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={panel.id === active.id}
                                    data-testid={`thread-panel-tab-${panel.id}`}
                                    onClick={() => setActiveId(panel.id)}
                                    className={cn('flex h-11 items-center text-[14px]', panel.id === active.id ? 'font-medium text-body' : 'text-muted')}
                                >
                                    {panel.label}
                                </button>
                            ))}
                        </div>
                        <div className="min-h-0 flex-1 overflow-auto px-4 py-5">
                            <active.component thread={thread} canDrive={canDrive} />
                        </div>
                    </aside>
                )}
            </div>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
