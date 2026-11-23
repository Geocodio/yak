import type { SettingsNavSection } from '@geocodio/console-ui';
import { Activity, Bell, Gauge, Inbox, KeyRound, Monitor, Puzzle, Server, Type, User, UserRound, Users, Video as VideoIcon, Zap } from 'lucide-react';
import { createElement } from 'react';
import { channels, mcp, prompts, skills, system } from '@/routes';
import { edit as profileEdit } from '@/routes/profile';
import { limits, linear, notifications, video } from '@/routes/settings';

export type SettingsSlug = 'profile' | 'claude-accounts' | 'devices' | 'people' | 'ssh-keys' | 'notifications' | 'prompts' | 'skills' | 'mcp' | 'linear' | 'video' | 'channels' | 'limits' | 'system';

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
            {
                slug: 'devices',
                // Route owned by W2-DEVICES, so the href is a literal rather than a Wayfinder import.
                href: '/settings/devices',
                label: 'Devices',
                icon: icon(Monitor),
                description: 'The computers and phones paired with Yak.',
                keywords: ['teleport', 'login', 'token', 'phone'],
            },
            {
                slug: 'notifications',
                href: notifications.url(),
                label: 'Notifications',
                icon: icon(Bell),
                description: 'Choose what Yak pushes to your devices.',
                keywords: ['push', 'install', 'phone'],
            },
            {
                slug: 'ssh-keys',
                // Route owned by W3-SSH, so the href is a literal rather than a Wayfinder import.
                href: '/settings/ssh-keys',
                label: 'SSH keys',
                icon: icon(KeyRound),
                description: 'The keys that can open a shell in the sandboxes of threads you drive.',
                keywords: ['ssh', 'github', 'terminal', 'public key'],
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
            {
                slug: 'people',
                href: '/settings/people',
                label: 'People',
                icon: icon(Users),
                description: 'Who can sign in to this Yak, and who is an admin.',
                keywords: ['admin', 'role', 'members', 'users'],
            },
            { slug: 'channels', href: channels.url(), label: 'Channels', icon: icon(Inbox), description: 'Slack, Linear, Sentry, and email inputs.' },
            {
                slug: 'limits',
                href: limits.url(),
                label: 'Limits',
                icon: icon(Gauge),
                description: 'How much Yak runs at once, how long things are kept, and how much it may spend. Only admins see this page. Changes apply to the next sandbox, task or cleanup run.',
                keywords: ['capacity', 'sandboxes', 'budget', 'retention', 'memory', 'cap'],
            },
            { slug: 'system', href: system.url(), label: 'System', icon: icon(Activity), description: 'Health and logs of the Yak installation.' },
        ],
    },
];
