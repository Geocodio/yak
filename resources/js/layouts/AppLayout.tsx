import { ToastHost } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import { AppCommandPalette } from '@/components/AppCommandPalette';
import { FlashToasts } from '@/components/FlashToasts';
import { Sidebar } from '@/components/Sidebar';

export function AppLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex h-dvh w-screen" data-testid="app-shell">
            <Sidebar />
            <div className="flex min-w-0 flex-1 flex-col bg-app">{children}</div>
            <FlashToasts />
            <AppCommandPalette />
            <ToastHost />
        </div>
    );
}
