import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { IconRail } from '@/components/shell/IconRail';
import { SectionPanel } from '@/components/shell/SectionPanel';
import { sectionForUrl, type RailSection } from '@/components/shell/sections';
import { ThreadListPanel } from '@/components/shell/ThreadListPanel';

const COLLAPSED_KEY = 'yak:shell-collapsed';

function readCollapsed(): boolean {
    try {
        return window.localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

function writeCollapsed(isCollapsed: boolean): void {
    try {
        window.localStorage.setItem(COLLAPSED_KEY, isCollapsed ? '1' : '0');
    } catch {
        // Storage can be blocked; the panel simply does not remember.
    }
}

/** Icon rail plus the panel of the active section; hidden below `lg`. */
export function ShellSidebar() {
    const { url } = usePage();
    const [picked, setPicked] = useState<RailSection | null>(null);
    const [isCollapsed, setIsCollapsed] = useState(readCollapsed);
    const active = picked ?? sectionForUrl(url);

    useEffect(() => {
        setPicked(null);
    }, [url]);

    const select = (section: RailSection) => {
        if (section === active && section === 'threads') {
            const next = !isCollapsed;
            setIsCollapsed(next);
            writeCollapsed(next);
            return;
        }
        setPicked(section);
        if (isCollapsed) {
            setIsCollapsed(false);
            writeCollapsed(false);
        }
    };

    const collapse = () => {
        setIsCollapsed(true);
        writeCollapsed(true);
    };

    return (
        <aside className="hidden shrink-0 border-r border-hair bg-sidebar lg:flex" data-testid="sidebar">
            <IconRail active={active} onSelect={select} />
            {isCollapsed ? null : (
                <div className="flex w-[300px] min-h-0 flex-col" data-testid="sidebar-panel">
                    {active === 'threads' ? <ThreadListPanel onCollapse={collapse} /> : <SectionPanel section={active} />}
                </div>
            )}
        </aside>
    );
}
