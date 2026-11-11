import type { SettingsNavSection } from '@geocodio/console-ui';
import { Activity, Inbox, Puzzle, Server, Type, User, UserRound, Video as VideoIcon, Zap } from 'lucide-react';
import { createElement } from 'react';
import { channels, mcp, prompts, skills, system } from '@/routes';
import { edit as profileEdit } from '@/routes/profile';
import { linear, video } from '@/routes/settings';

export type SettingsSlug = 'profile' | 'claude-accounts' | 'prompts' | 'skills' | 'mcp' | 'linear' | 'video' | 'channels' | 'system';

const icon = (component: typeof User) => createElement(component, { size: 15 });

/** Later tracks add their items here (Notifications, SSH keys and Devices under Account). */
export const SETTINGS_SECTIONS: SettingsNavSection[] = [
    {
        title: 'Account',
        items: [
            {
                slug: 'profile',
                href: profileEdit.url(),
                label: 'Profile',
                icon: icon(User),
                description: 'Update your name, email address, and Slack direct messages.',
                keywords: ['name', 'email'],
            },
            {
                slug: 'claude-accounts',
                // Route owned by W1-ACCOUNTS, so the href is a literal rather than a Wayfinder import.
                href: '/settings/claude-accounts',
                label: 'Claude accounts',
                icon: icon(UserRound),
                description: 'The Claude accounts Yak runs sessions on.',
                keywords: ['subscription', 'login'],
            },
        ],
    },
    {
        title: 'Agent',
        items: [
            { slug: 'prompts', href: prompts.url(), label: 'Prompts', icon: icon(Type), description: 'The prompt templates Yak builds sessions from.' },
            { slug: 'skills', href: skills.url(), label: 'Skills', icon: icon(Puzzle), description: 'Skills installed for Yak sessions.' },
            { slug: 'mcp', href: mcp.url(), label: 'MCP servers', icon: icon(Server), description: 'Tool servers available to Yak sessions.' },
        ],
    },
    {
        title: 'Workspace',
        items: [
            {
                slug: 'linear',
                href: linear.url(),
                label: 'Linear',
                icon: icon(Zap),
                description: 'Connect Yak to your Linear workspace so comments and issue updates post as the Yak app.',
                keywords: ['oauth', 'issues'],
            },
            {
                slug: 'video',
                href: video.url(),
                label: 'Video walkthroughs',
                icon: icon(VideoIcon),
                description: 'How the walkthrough attached to every PR looks. Changes apply to the next render.',
                keywords: ['theme', 'fonts', 'logo', 'voiceover'],
            },
            { slug: 'channels', href: channels.url(), label: 'Channels', icon: icon(Inbox), description: 'Slack, Linear, Sentry, and email inputs.' },
            { slug: 'system', href: system.url(), label: 'System', icon: icon(Activity), description: 'Health and logs of the Yak installation.' },
        ],
    },
];
