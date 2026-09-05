import { Sheet } from '@geocodio/console-ui';
import { useEffect, useState } from 'react';
import { SidebarNav } from '@/components/Sidebar';

/**
 * The navigation drawer below `lg`, opened by the top bar's menu button via
 * the `yak:open-nav` event so pages never hold drawer state themselves.
 */
export function MobileNavSheet() {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const onOpen = () => setOpen(true);
        window.addEventListener('yak:open-nav', onOpen);
        return () => window.removeEventListener('yak:open-nav', onOpen);
    }, []);

    return (
        <Sheet open={open} onOpenChange={setOpen} side="bottom" title="Navigation" hideTitle data-testid="mobile-nav">
            <div className="-mx-2 flex min-h-0 flex-1 flex-col overflow-y-auto pb-2">
                <SidebarNav touch onNavigate={() => setOpen(false)} />
            </div>
        </Sheet>
    );
}
