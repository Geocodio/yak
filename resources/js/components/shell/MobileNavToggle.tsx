import { PanelLeft } from 'lucide-react';
import { openMobileNav } from '@/components/Sidebar';

/** The header panel toggle shown below `lg`; it opens the left navigation drawer and sits over the page header's left edge. */
export function MobileNavToggle() {
    return (
        <button
            type="button"
            aria-label="Open navigation"
            onClick={openMobileNav}
            data-testid="mobile-nav-toggle"
            className="absolute left-1 top-0.5 z-10 flex h-11 w-11 items-center justify-center rounded-control text-muted hover:bg-panel-2 lg:hidden"
        >
            <PanelLeft size={18} />
        </button>
    );
}
