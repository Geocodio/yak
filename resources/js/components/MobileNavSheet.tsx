import { Sheet } from '@geocodio/console-ui';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { SidebarNav, openPalette } from '@/components/Sidebar';

/**
 * The navigation drawer is opened by the tab bar's More slot through the
 * `yak:open-nav` event so pages never hold drawer state themselves.
 *
 * It enters from the right because a "More" page conventionally slides in from
 * that edge. The top padding clears the status bar when Yak runs from the home
 * screen under `viewport-fit=cover`. Search sits at the top of the drawer
 * because phones have no app bar to hold it.
 */
export function MobileNavSheet() {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onOpen = () => setOpen(true);
        window.addEventListener('yak:open-nav', onOpen);
        return () => window.removeEventListener('yak:open-nav', onOpen);
    }, []);

    return (
        <Sheet
            open={open}
            onOpenChange={setOpen}
            side="right"
            width="w-[min(19rem,84vw)]"
            title="Navigation"
            hideTitle
            className="pt-[max(1rem,env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))]"
            data-testid="mobile-nav"
        >
            <div className="-mx-2 flex min-h-0 flex-1 flex-col overflow-y-auto">
                <button
                    type="button"
                    onClick={() => {
                        setOpen(false);
                        openPalette();
                    }}
                    className="mx-2 mb-2 flex h-10 items-center gap-2 rounded-control border border-hair bg-panel px-2 text-[14px] text-faint shadow-card"
                    data-testid="mobile-nav-search"
                >
                    <Search size={17} />
                    Search…
                </button>
                <SidebarNav touch onNavigate={() => setOpen(false)} />
            </div>
        </Sheet>
    );
}
