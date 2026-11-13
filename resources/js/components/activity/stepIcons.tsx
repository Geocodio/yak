import { cn } from '@geocodio/console-ui';
import { FileText, Globe, LoaderCircle, MessageCircleMore, Pencil, Search, Terminal, type LucideIcon } from 'lucide-react';
import type { StepCategory } from './types';

export const STEP_ICONS: Record<StepCategory, LucideIcon> = {
    command: Terminal,
    read: FileText,
    edit: Pencil,
    search: Search,
    fetch: Globe,
    thinking: MessageCircleMore,
};

/** The small rounded square that carries a step's icon. */
export function StepTile({ category, failed = false, current = false, ringed = false }: { category: StepCategory; failed?: boolean; current?: boolean; ringed?: boolean }) {
    const Icon = current ? LoaderCircle : STEP_ICONS[category];

    return (
        <span
            className={cn(
                'relative grid size-6 shrink-0 place-items-center rounded-[7px]',
                current ? 'bg-panel text-accent-text' : failed ? 'bg-fail-soft text-fail' : 'bg-panel-2 text-muted',
                ringed && 'ring-2 ring-panel',
            )}
        >
            <Icon size={14} className={cn('shrink-0', current && 'activity-spin')} aria-hidden />
        </span>
    );
}

const STACK_ORDER: StepCategory[] = ['command', 'read', 'edit', 'search', 'fetch', 'thinking'];

/** The overlapping tiles of a folded group: one per kind of step it holds. */
export function StepStack({ categories }: { categories: Set<StepCategory> | StepCategory[] }) {
    const present = new Set(categories);

    return (
        <span className="flex shrink-0">
            {STACK_ORDER.filter((category) => present.has(category)).map((category, index) => (
                <span key={category} className={cn(index > 0 && '-ml-[5px]')}>
                    <StepTile category={category} ringed />
                </span>
            ))}
        </span>
    );
}
