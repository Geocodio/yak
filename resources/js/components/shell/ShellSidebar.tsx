import { usePage } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { useEffect, useRef, useState } from 'react';
import { IconRail } from '@/components/shell/IconRail';
import { SectionPanel } from '@/components/shell/SectionPanel';
import { sectionForUrl, type RailSection } from '@/components/shell/sections';
import { ThreadListPanel } from '@/components/shell/ThreadListPanel';
import { useMarkThreadSeen } from '@/components/shell/useMarkThreadSeen';
import { useThreadDocked } from '@/components/shell/useThreadDocked';

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

/** Icon rail plus the panel of the active section; hidden below `lg`. A docked thread panel leaves only the rail. */
export function ShellSidebar() {
    const { url } = usePage();
    const [picked, setPicked] = useState<RailSection | null>(null);
    const [isCollapsed, setIsCollapsed] = useState(readCollapsed);
    const isDocked = useThreadDocked();
    // A docked thread panel hides the sidebar until the person asks for it with the rail.
    const [isOpenedOverDock, setIsOpenedOverDock] = useState(false);
    const active = picked ?? sectionForUrl(url);
    const isPanelHidden = isCollapsed || (isDocked && !isOpenedOverDock);

    useMarkThreadSeen(url);

    useEffect(() => {
        setPicked(null);
    }, [url]);

    useEffect(() => {
        setIsOpenedOverDock(false);
    }, [url, isDocked]);

    const open = () => {
        setIsOpenedOverDock(isDocked);
        if (isCollapsed) {
            setIsCollapsed(false);
            writeCollapsed(false);
        }
    };

    const collapse = () => {
        setIsOpenedOverDock(false);
        setIsCollapsed(true);
        writeCollapsed(true);
    };

    const select = (section: RailSection) => {
        if (section === active && section === 'threads' && !isPanelHidden) {
            collapse();
            return;
        }
        setPicked(section);
        open();
    };

    const togglePanel = () => {
        if (isPanelHidden) {
            open();
        } else {
            collapse();
        }
    };

    // The "[" shortcut reads the latest toggle without re-binding the listener.
    const togglePanelRef = useRef(togglePanel);
    togglePanelRef.current = togglePanel;

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target;
            const isTyping = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
            if (event.key !== '[' || event.metaKey || event.ctrlKey || event.altKey || isTyping) {
                return;
            }
            togglePanelRef.current();
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    return (
        <aside className="hidden shrink-0 border-r border-hair bg-sidebar lg:flex" data-testid="sidebar">
            <IconRail active={active} onSelect={select} onShowThreads={isPanelHidden ? open : undefined} />
            <div
                className={cn('min-h-0 overflow-hidden transition-[width] duration-200 ease-out motion-reduce:transition-none', isPanelHidden ? 'w-0' : 'w-[300px]')}
                aria-hidden={isPanelHidden || undefined}
                inert={isPanelHidden}
                data-testid="sidebar-panel"
            >
                <div className="flex h-full w-[300px] min-h-0 flex-col">
                    {active === 'threads' ? <ThreadListPanel onCollapse={collapse} /> : <SectionPanel section={active} onCollapse={collapse} />}
                </div>
            </div>
        </aside>
    );
}
