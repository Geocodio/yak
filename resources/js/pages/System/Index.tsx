import { Head, router } from '@inertiajs/react';
import { PageHeader, cn } from '@geocodio/console-ui';
import {
    TelemetryEntities,
    TelemetryEntity,
    TelemetryExplore,
    TelemetryIssue,
    TelemetryPage,
    TelemetryToolbar,
    TelemetryTrace,
    TelemetryUiProvider,
    useBoot,
} from '@cboxdk/telemetry-ui';
import '../../../css/telemetry-ui.css';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { useMediaQuery } from '@/lib/useMediaQuery';
import type { PageProps } from '@/types/shared';
import type { SystemPageProps } from '@/types/system';

/** Where the section lives; the package's own paths sit under it unchanged. */
const SECTION_PATH = '/system';

/** The window and scope carried from page to page; filters stay behind. */
const CARRIED_PARAMS = ['period', 'from', 'to', 'service', 'env', 'refresh'];

/** A package path (`/p/queues`, `/traces/<id>`) as a Yak URL. */
function sectionUrl(pathname: string, search = ''): string {
    return `${SECTION_PATH}${pathname === '/' ? '' : pathname}${search}`;
}

function carriedSearch(): string {
    const current = new URLSearchParams(window.location.search);
    const kept = new URLSearchParams();

    for (const key of CARRIED_PARAMS) {
        const value = current.get(key);
        if (value) {
            kept.set(key, value);
        }
    }

    const search = kept.toString();

    return search === '' ? '' : `?${search}`;
}

/** The package page for `path`, by the package's own path scheme. */
function Body({ path }: { path: string }) {
    // The controller only passes `section/id`, with an id of plain characters.
    const [head, tail] = path.split('/');

    switch (head) {
        case 'p':
            return <TelemetryPage page={tail} />;
        case 'explore':
            return (
                <TelemetryExplore
                    signal={tail as 'requests' | 'traces' | 'logs' | 'errors'}
                    onSignalChange={(signal) => window.history.replaceState(window.history.state, '', sectionUrl(`/explore/${signal}`, window.location.search))}
                />
            );
        case 'entities':
            return <TelemetryEntities type={tail} />;
        case 'entity':
            return <TelemetryEntity type={tail} />;
        case 'traces':
            return <TelemetryTrace traceId={tail} />;
        case 'errors':
            return <TelemetryIssue group={tail} />;
        default:
            return null;
    }
}

/** Every page the package found data for, in its own groups, with Explore after the overview. */
function SectionNav({ pathname }: { pathname: string }) {
    const boot = useBoot();

    const pages = boot.nav.map((group) => ({
        group: group.group,
        items: group.pages.map((page) => ({ href: `/p/${page.slug}`, label: page.label })),
    }));
    const isOverview = (group: { items: { href: string }[] }) => group.items.some((item) => item.href === '/p/dashboard');
    const groups = [
        ...pages.filter(isOverview),
        { group: 'Explore', items: boot.explore.map((entry) => ({ href: `/explore/${entry.signal}`, label: entry.label })) },
        ...pages.filter((group) => !isOverview(group)),
    ];

    return (
        <nav aria-label="System" className="flex flex-col gap-4 max-lg:flex-row max-lg:flex-wrap max-lg:gap-x-6" data-testid="system-nav">
            {groups.map((group) => (
                <div key={group.group}>
                    <div className="mb-1 px-2 text-[11px] font-medium text-faint">{group.group}</div>
                    <ul className="flex flex-col">
                        {group.items.map((item) => (
                            <li key={item.href}>
                                <a
                                    href={sectionUrl(item.href)}
                                    onClick={(event) => {
                                        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                                            return;
                                        }
                                        event.preventDefault();
                                        router.visit(sectionUrl(item.href, carriedSearch()));
                                    }}
                                    aria-current={item.href === pathname ? 'page' : undefined}
                                    className={cn(
                                        'block rounded-chip px-2 py-1 text-[12px] text-muted hover:bg-panel-2 hover:text-body',
                                        item.href === pathname && 'bg-accent-soft text-accent-text hover:bg-accent-soft hover:text-accent-text',
                                    )}
                                >
                                    {item.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

/**
 * The System section: cboxdk/laravel-telemetry-ui's own pages inside Yak's
 * layout. The package's links become Yak URLs under /system, and the window,
 * filters and open drawer live in the address bar so a view survives a reload.
 */
export default function Index({ telemetryBase, path }: PageProps<SystemPageProps>) {
    const prefersDark = useMediaQuery('(prefers-color-scheme: dark)');
    const pathname = `/${path}`;
    const [search, setSearch] = useState(() => window.location.search);
    const onSearchChange = useCallback((next: string) => {
        setSearch(next);
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${next}`);
    }, []);

    return (
        <>
            <Head title="System" />
            <TelemetryUiProvider
                key={path}
                config={{ base: telemetryBase }}
                className={prefersDark ? 'dark' : undefined}
                pathname={pathname}
                search={search}
                onSearchChange={onSearchChange}
                onNavigate={({ pathname: to, search: next }) => router.visit(sectionUrl(to, next))}
                href={({ pathname: to, search: next }) => sectionUrl(to, next)}
            >
                <PageHeader crumbs={['System']}>
                    <div className="ml-4 min-w-0">
                        <TelemetryToolbar />
                    </div>
                </PageHeader>

                <div className="min-h-0 flex-1 overflow-auto">
                    <div className="mx-auto grid max-w-[1400px] gap-6 px-4 py-6 sm:px-8 lg:grid-cols-[180px_minmax(0,1fr)]">
                        <SectionNav pathname={pathname} />
                        <div className="min-w-0" data-testid="system-body">
                            <Body path={path} />
                        </div>
                    </div>
                </div>
            </TelemetryUiProvider>
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
