import { Link } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import prompts from '@/routes/prompts';
import type { PromptGroup } from '@/types/prompts';

export function PromptSidebar({ groups, activeSlug }: { groups: PromptGroup[]; activeSlug: string }) {
    return (
        <aside className="w-[240px] shrink-0 overflow-auto border-r border-hair bg-sidebar py-3" data-testid="prompt-sidebar">
            {groups.map(({ group, items }) => (
                <div key={group} className="mb-3">
                    <div className="px-4 pb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">{group}</div>
                    {items.map(({ slug, label, type, customized }) => (
                        <Link
                            key={slug}
                            href={prompts.show.url(slug)}
                            className={cn(
                                'flex w-full items-center gap-2 px-4 py-1.5 text-left text-[12.5px] text-muted hover:bg-panel-2 hover:text-body',
                                activeSlug === slug && 'bg-accent-soft text-accent-text hover:bg-accent-soft',
                            )}
                            data-testid={`prompt-item-${slug}`}
                        >
                            <span className="flex-1 truncate">{label}</span>
                            {customized && <span className="h-1.5 w-1.5 rounded-pill bg-accent" title="Customized" />}
                            <span className="font-mono text-[10px] uppercase text-faint">{type}</span>
                        </Link>
                    ))}
                </div>
            ))}
        </aside>
    );
}
