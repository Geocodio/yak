import { BrandMarkIcon, Sheet } from '@geocodio/console-ui';
import { router, usePage } from '@inertiajs/react';
import { LogOut, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { MobileSectionSwitcher } from '@/components/shell/MobileSectionSwitcher';
import { SectionPanel } from '@/components/shell/SectionPanel';
import { sectionForUrl, type RailSection } from '@/components/shell/sections';
import { ThreadListPanel } from '@/components/shell/ThreadListPanel';
import { YAK_MARK } from '@/lib/brand';
import { logout } from '@/routes';
import type { SharedProps } from '@/types/shared';

/**
 * The navigation drawer is opened by the tab bar's More slot through the
 * `yak:open-nav` event so pages never hold drawer state themselves.
 *
 * It enters from the left (console-ui's Sheet only knows right and bottom, so
 * the right variant is anchored left with `mr-auto` and its slide is mirrored;
 * swipe-to-dismiss still tracks the right edge). The header panel toggle and the tab bar's More slot
 * open it. The top padding clears the status bar when Yak runs from the home
 * screen under `viewport-fit=cover`. The sheet holds the same section panels as
 * the desktop sidebar in touch sizing, behind a four-icon switcher; the thread
 * list's search field replaces the old standalone search button.
 */
export function MobileNavSheet() {
    const { props, url } = usePage<SharedProps>();
    const user = props.auth.user;
    const [open, setOpen] = useState(false);
    const [picked, setPicked] = useState<RailSection | null>(null);
    const active = picked ?? sectionForUrl(url);

    useEffect(() => {
        setOpen(false);
        setPicked(null);
    }, [url]);

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
            className="mr-auto border-l-0 border-r data-[ending-style]:[transform:translateX(-100%)]! data-[starting-style]:[transform:translateX(-100%)]! pt-[max(1rem,env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))]"
            data-testid="mobile-nav"
        >
            <div className="mb-3 flex items-center gap-2 px-2" data-testid="mobile-nav-header">
                <BrandMarkIcon mark={YAK_MARK} size={22} />
                <span className="flex-1 text-[15px] font-semibold tracking-tight">Yak</span>
                <button
                    type="button"
                    aria-label="Close navigation"
                    onClick={() => setOpen(false)}
                    data-testid="mobile-nav-close"
                    className="flex h-9 w-9 items-center justify-center rounded-control text-muted hover:bg-panel-2"
                >
                    <X size={18} />
                </button>
            </div>
            <MobileSectionSwitcher active={active} onSelect={setPicked} />
            <div className="-mx-2 flex min-h-0 flex-1 flex-col overflow-y-auto">
                {active === 'threads' ? <ThreadListPanel touch /> : <SectionPanel section={active} touch onNavigate={() => setOpen(false)} />}
            </div>
            {user ? (
                <div className="mt-2 flex items-center gap-2 border-t border-hair px-2 pt-3" data-testid="mobile-nav-user">
                    <span className="flex h-8 w-8 items-center justify-center rounded-pill bg-panel-2 text-[11px] font-medium">{user.initials}</span>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-[13px] font-medium">{user.name}</div>
                        <div className="truncate text-[11px] text-muted">{user.email}</div>
                    </div>
                    <button
                        type="button"
                        onClick={() => router.post(logout.url())}
                        className="flex h-10 items-center gap-1.5 rounded-control px-2 text-[13px] text-muted hover:bg-panel-2"
                    >
                        <LogOut size={15} />
                        Log out
                    </button>
                </div>
            ) : null}
        </Sheet>
    );
}
