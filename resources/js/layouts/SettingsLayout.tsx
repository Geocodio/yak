import { PageHeader } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import { SETTINGS_SECTIONS, type SettingsSlug } from '@/components/shell/settingsSections';
import { AppLayout } from '@/layouts/AppLayout';

export function SettingsLayout({ slug, children }: { slug: SettingsSlug; children: ReactNode }) {
    const item = SETTINGS_SECTIONS.flatMap((section) => section.items).find((candidate) => candidate.slug === slug);
    const label = item?.label ?? 'Settings';
    const isWide = slug === 'video' || slug === 'channels' || slug === 'claude-accounts' || slug === 'people';

    return (
        <AppLayout>
            <PageHeader crumbs={['Settings', label]} />
            <div className="min-h-0 flex-1 overflow-auto">
                <div className={`mx-auto w-full px-4 py-8 sm:px-8 ${isWide ? 'max-w-5xl' : 'max-w-[560px]'}`}>
                    <h1 className="text-[18px] font-semibold">{label}</h1>
                    {item?.description ? <p className="mb-6 text-muted">{item.description}</p> : null}
                    {children}
                </div>
            </div>
        </AppLayout>
    );
}
