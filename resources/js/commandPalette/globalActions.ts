import { router, usePage } from '@inertiajs/react';
import { toast } from '@geocodio/console-ui';
import { useMemo } from 'react';
import { NAV_ITEMS, SECONDARY_NAV_ITEMS, SETTINGS_ITEM, openNewThread } from '@/components/Sidebar';
import { home } from '@/routes';
import { claudeAccounts, devices, linear, notifications, people, sshKeys, video } from '@/routes/settings';
import { edit as profileEdit } from '@/routes/profile';
import { show as showThread } from '@/routes/threads';
import type { SharedProps } from '@/types/shared';
import type { PaletteAction } from './types';
import { useCommandPaletteActions } from './useCommandPaletteActions';

export const NEW_THREAD_ID = 'new-thread';
export const NEW_THREAD_KEYWORDS = ['create', 'new', 'start', 'spawn', 'task'];

const SETTINGS_PAGES = [
    { label: 'Profile', url: profileEdit.url() },
    { label: 'Devices', url: devices.url() },
    { label: 'SSH keys', url: sshKeys.url() },
    { label: 'Claude accounts', url: claudeAccounts.url() },
    { label: 'People', url: people.url() },
    { label: 'Notifications', url: notifications.url() },
    { label: 'Linear', url: linear.url() },
    { label: 'Video', url: video.url() },
];

const slugify = (label: string): string => label.toLowerCase().replace(/[^a-z0-9]+/g, '-');

const goTo = (id: string, label: string, url: string, keywords: string[] = []): PaletteAction => ({
    id,
    label,
    keywords: ['go', 'open', ...keywords],
    href: url,
    run: () => router.visit(url),
});

/** Registers the actions available on every page and returns them. Called once, by `AppCommandPalette`. */
export function useGlobalActions(): PaletteAction[] {
    const { props, url } = usePage<SharedProps>();
    const currentThreadId = /^\/threads\/([^/?#]+)/.exec(url.split('?')[0])?.[1] ?? null;
    const nextThread = props.threadList?.needsYou.find((row) => row.id !== currentThreadId) ?? null;
    const nextThreadId = nextThread?.id ?? null;

    const create = useMemo<PaletteAction[]>(() => [{ id: NEW_THREAD_ID, label: 'New thread', keywords: NEW_THREAD_KEYWORDS, shortcut: 'newThread', run: () => openNewThread() }], []);

    const goToActions = useMemo<PaletteAction[]>(() => {
        const nextUrl = nextThreadId === null ? null : showThread.url(nextThreadId);

        return [
            {
                id: 'next-needs-you',
                label: 'Next thread that needs you',
                keywords: ['inbox', 'waiting', 'blocked', 'question'],
                shortcut: 'nextNeedsYou',
                unavailable: nextUrl === null ? 'Nothing needs you' : undefined,
                href: nextUrl ?? undefined,
                run: () => {
                    if (nextUrl !== null) {
                        router.visit(nextUrl);
                    }
                },
            },
            { ...goTo('go-home', 'Home', home.url(), ['dashboard']), shortcut: 'goHome' },
            ...[...NAV_ITEMS, ...SECONDARY_NAV_ITEMS, SETTINGS_ITEM].map((item) => goTo(`go-${slugify(item.label)}`, item.label, item.url)),
        ];
    }, [nextThreadId]);

    const settings = useMemo<PaletteAction[]>(() => SETTINGS_PAGES.map((page) => goTo(`settings-${slugify(page.label)}`, page.label, page.url, ['settings'])), []);

    const help = useMemo<PaletteAction[]>(
        () => [
            {
                id: 'show-shortcuts',
                label: 'Keyboard shortcuts',
                keywords: ['keys', 'hotkeys', 'help'],
                shortcut: 'showShortcuts',
                run: () => window.dispatchEvent(new CustomEvent('yak:open-shortcuts')),
            },
            {
                id: 'copy-link',
                label: 'Copy link to this page',
                keywords: ['url', 'share', 'clipboard'],
                run: () => {
                    navigator.clipboard
                        .writeText(window.location.href)
                        .then(() => toast.success('Link copied'))
                        .catch(() => toast.error('Could not copy the link'));
                },
            },
        ],
        [],
    );

    useCommandPaletteActions('Create', create);
    useCommandPaletteActions('Go to', goToActions);
    useCommandPaletteActions('Settings', settings);
    useCommandPaletteActions('Help', help);

    return useMemo(() => [...create, ...goToActions, ...settings, ...help], [create, goToActions, settings, help]);
}
