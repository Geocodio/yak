import { Frame, Hammer, Telescope } from 'lucide-react';

const ICONS = { research: Telescope, mockup: Frame } as const;

/** The kind of work a sub-thread does, in a small tile. Implement and general share the hammer. */
export function KindIcon({ kind }: { kind: string }) {
    const Icon = ICONS[kind as keyof typeof ICONS] ?? Hammer;

    return (
        <span className="grid size-7 shrink-0 place-items-center rounded-chip bg-panel-2 text-muted" data-kind={kind} aria-hidden>
            <Icon size={14} />
        </span>
    );
}
