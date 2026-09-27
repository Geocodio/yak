import { usePage } from '@inertiajs/react';
import { ToastHost, useBrandFavicon } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import { AppCommandPalette } from '@/components/AppCommandPalette';
import { FlashToasts } from '@/components/FlashToasts';
import { MobileNavSheet } from '@/components/MobileNavSheet';
import { MobileTabBar } from '@/components/MobileTabBar';
import { Sidebar } from '@/components/Sidebar';
import { YAK_ACTIVITY_PIP, YAK_BRAND_COLOR, YAK_MARK } from '@/lib/brand';
import type { SharedProps } from '@/types/shared';

export function AppLayout({ children }: { children: ReactNode }) {
    const { activeTaskCount } = usePage<SharedProps>().props.nav;

    // The tab favicon wears a pip while tasks are running, so a backgrounded
    // Yak tab still says whether anything is in flight.
    useBrandFavicon(YAK_MARK, YAK_BRAND_COLOR, { pip: activeTaskCount > 0 ? YAK_ACTIVITY_PIP : null });

    // Below `lg` there is no app bar: each page's own header is the top row,
    // and the content column pads itself clear of the status bar when Yak
    // runs from the home screen under `viewport-fit=cover`.
    return (
        <div className="flex h-dvh w-full flex-col lg:flex-row" data-testid="app-shell">
            <Sidebar />
            <div className="flex min-h-0 min-w-0 flex-1 flex-col bg-app max-lg:pt-[env(safe-area-inset-top)]">{children}</div>
            <MobileTabBar />
            <MobileNavSheet />
            <FlashToasts />
            <AppCommandPalette />
            <ToastHost className="max-lg:bottom-[calc(5.75rem+env(safe-area-inset-bottom))]" />
        </div>
    );
}
