import { Link, router, usePage } from '@inertiajs/react';
import { SettingsShell, type SettingsNavSection } from '@geocodio/console-ui';
import { Inbox, Server, User, Video as VideoIcon, Zap } from 'lucide-react';
import type { ReactNode } from 'react';
import { channels, tasks } from '@/routes';
import { edit as profileEdit } from '@/routes/profile';
import { linear, mcp, video } from '@/routes/settings';

const SECTIONS: SettingsNavSection[] = [
    {
        title: 'Account',
        items: [
            {
                slug: 'profile',
                href: profileEdit.url(),
                label: 'Profile',
                icon: <User size={15} />,
                description: 'Update your name and email address.',
                keywords: ['name', 'email'],
            },
        ],
    },
    {
        title: 'Workspace',
        items: [
            {
                slug: 'linear',
                href: linear.url(),
                label: 'Linear',
                icon: <Zap size={15} />,
                description: 'Connect Yak to your Linear workspace so comments and issue updates post as the Yak app.',
                keywords: ['oauth', 'issues'],
            },
            {
                slug: 'video',
                href: video.url(),
                label: 'Video walkthroughs',
                icon: <VideoIcon size={15} />,
                description: 'How the walkthrough attached to every PR looks. Changes apply to the next render.',
                keywords: ['theme', 'fonts', 'logo', 'voiceover'],
            },
            {
                slug: 'channels',
                href: channels.url(),
                label: 'Channels',
                icon: <Inbox size={15} />,
                description: 'Slack, Linear, and Sentry inputs.',
            },
            {
                slug: 'mcp',
                href: mcp.url(),
                label: 'MCP servers',
                icon: <Server size={15} />,
                description: 'Tool servers agents can reach inside every sandbox, and their logins.',
                keywords: ['mcp', 'tools', 'oauth'],
            },
        ],
    },
];

export function SettingsLayout({ slug, children }: { slug: 'profile' | 'linear' | 'video' | 'channels' | 'mcp'; children: ReactNode }) {
    const currentPath = usePage().url.split('?')[0];

    return (
        <SettingsShell
            sections={SECTIONS}
            slug={slug}
            currentPath={currentPath}
            backHref={tasks.url()}
            backLabel="Back to Yak"
            wide={slug === 'video' || slug === 'channels' || slug === 'mcp'}
            LinkComponent={Link}
            onNavigate={(href) => router.visit(href)}
        >
            {children}
        </SettingsShell>
    );
}
