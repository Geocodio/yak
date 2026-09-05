import { Link } from '@inertiajs/react';
import { AppBrand, IconButton } from '@geocodio/console-ui';
import { Menu as MenuIcon, Plus, Search } from 'lucide-react';
import { openMobileNav, openNewTask, openPalette } from '@/components/Sidebar';
import { YAK_MARK } from '@/lib/brand';
import { tasks } from '@/routes';

/**
 * The app bar shown below `lg` in place of the sidebar: brand lockup on the
 * left, search / new task / menu on the right. The top padding keeps it clear
 * of the status bar when Yak runs from the home screen with a translucent
 * status bar and `viewport-fit=cover`.
 */
export function MobileTopBar() {
    return (
        <div className="shrink-0 border-b border-hair bg-sidebar pt-[env(safe-area-inset-top)] lg:hidden" data-testid="mobile-top-bar">
            <div className="flex h-12 items-center gap-1 px-3">
                <AppBrand name="Yak" mark={YAK_MARK} href={tasks.url()} LinkComponent={Link} className="mr-auto px-1" />
                <IconButton label="Search" onClick={openPalette} className="h-9 w-9">
                    <Search size={17} />
                </IconButton>
                <IconButton label="New task" onClick={openNewTask} className="h-9 w-9">
                    <Plus size={17} />
                </IconButton>
                <IconButton label="Menu" onClick={openMobileNav} className="h-9 w-9" data-testid="mobile-nav-trigger">
                    <MenuIcon size={17} />
                </IconButton>
            </div>
        </div>
    );
}
