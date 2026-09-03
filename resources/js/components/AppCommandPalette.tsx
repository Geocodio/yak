import { router } from '@inertiajs/react';
import { CommandPalette, type CommandPaletteSection } from '@geocodio/console-ui';
import { useEffect, useState } from 'react';
import type React from 'react';
import { NAV_ITEMS, SECONDARY_NAV_ITEMS, SETTINGS_ITEM, openNewTask } from '@/components/Sidebar';

const ALL_NAV = [...NAV_ITEMS, ...SECONDARY_NAV_ITEMS, SETTINGS_ITEM];

export function AppCommandPalette() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
                event.preventDefault();
                setOpen((value) => !value);
            }
        };
        const onOpenRequest = () => setOpen(true);
        window.addEventListener('keydown', onKey);
        window.addEventListener('yak:open-palette', onOpenRequest);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('yak:open-palette', onOpenRequest);
        };
    }, []);

    const filtered = ALL_NAV.filter((item) => item.label.toLowerCase().includes(query.toLowerCase()));

    const sections: CommandPaletteSection[] = [
        {
            title: 'Navigate',
            items: filtered.map((item) => ({
                id: item.label,
                label: item.label,
                hint: 'page',
                href: item.url,
                onSelect: () => {
                    router.visit(item.url);
                    setOpen(false);
                },
            })),
        },
        {
            title: 'Actions',
            items: [
                { id: 'new', label: 'New task', shortcut: ['C'], onSelect: () => { openNewTask(); setOpen(false); } },
            ].filter((action) => action.label.toLowerCase().includes(query.toLowerCase())),
        },
    ];

    return (
        <CommandPalette
            open={open}
            onOpenChange={setOpen}
            query={query}
            onQueryChange={setQuery}
            placeholder="Jump to a task, repo, or page…"
            sections={sections}
            inputProps={{ 'data-testid': 'app-palette' } as React.InputHTMLAttributes<HTMLInputElement>}
        />
    );
}
