import { usePage } from '@inertiajs/react';
import { ToastHost, useBrandFavicon } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import { AppCommandPalette } from '@/components/AppCommandPalette';
import { FlashToasts } from '@/components/FlashToasts';
import { MobileNavSheet } from '@/components/MobileNavSheet';
import { MobileTopBar } from '@/components/MobileTopBar';
import { Sidebar } from '@/components/Sidebar';
import { YAK_ACTIVITY_PIP, YAK_BRAND_COLOR, YAK_MARK } from '@/lib/brand';
import type { SharedProps } from '@/types/shared';

export function AppLayout({ children }: { children: ReactNode }) {
    const { activeTaskCount } = usePage<SharedProps>().props.nav;

    // The tab favicon wears a pip while tasks are running, so a backgrounded
    // Yak tab still says whether anything is in flight.
    useBrandFavicon(YAK_MARK, YAK_BRAND_COLOR, { pip: activeTaskCount > 0 ? YAK_ACTIVITY_PIP : null });

    return (
        <div className="flex h-dvh w-full flex-col lg:flex-row" data-testid="app-shell">
            <MobileTopBar />
            <Sidebar />
            <div className="flex min-h-0 min-w-0 flex-1 flex-col bg-app max-lg:pb-[env(safe-area-inset-bottom)]">{children}</div>
            <MobileNavSheet />
            <FlashToasts />
            <AppCommandPalette />
            <ToastHost />
        </div>
    );
}
