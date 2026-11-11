import { Link, router, usePage } from '@inertiajs/react';
import { SettingsShell, ToastHost, type SettingsNavSection } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import { FlashToasts } from '@/components/FlashToasts';
import { SETTINGS_SECTIONS, type SettingsSlug } from '@/components/shell/settingsSections';
import { tasks } from '@/routes';

export function SettingsLayout({ slug, children }: { slug: SettingsSlug; children: ReactNode }) {
    const currentPath = usePage().url.split('?')[0];

    // Settings pages render outside AppLayout, so they mount their own toast
    // host; without it success flashes and server errors fire unseen.
    return (
        <>
            <FlashToasts />
            <ToastHost />
            <SettingsShell
            sections={SETTINGS_SECTIONS}
            slug={slug}
            currentPath={currentPath}
            backHref={tasks.url()}
            backLabel="Back to Yak"
            wide={slug === 'video' || slug === 'channels'}
            LinkComponent={Link}
            onNavigate={(href) => router.visit(href)}
        >
                {children}
            </SettingsShell>
        </>
    );
}
