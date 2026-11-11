import { dockPanelsFor } from '@/threads/panels';
import type { ThreadData } from '@/types/threads';

/** Body of the open dock panel, beside the conversation on large screens. Tabs live in the page header. */
export function DockHost({ thread, canDrive, activeId }: { thread: ThreadData; canDrive: boolean; activeId: string | null }) {
    const active = dockPanelsFor(thread).find((panel) => panel.id === activeId);

    if (!active) {
        return null;
    }

    return (
        <aside className="hidden w-[560px] shrink-0 flex-col border-l border-hair bg-app lg:flex" data-testid={`thread-dock-${active.id}`}>
            <active.component thread={thread} canDrive={canDrive} />
        </aside>
    );
}
