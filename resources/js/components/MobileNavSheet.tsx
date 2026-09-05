import { Sheet } from '@geocodio/console-ui';
import { useEffect, useState } from 'react';
import { SidebarNav } from '@/components/Sidebar';

/**
 * The navigation drawer below `lg`, opened by the top bar's menu button via
 * the `yak:open-nav` event so pages never hold drawer state themselves.
 *
 * It enters from the right, the edge the menu button sits on, so the panel
 * comes from where the thumb pressed. The top padding clears the status bar
 * when Yak runs from the home screen under `viewport-fit=cover`.
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
                <SidebarNav touch onNavigate={() => setOpen(false)} />
            </div>
        </Sheet>
    );
}
