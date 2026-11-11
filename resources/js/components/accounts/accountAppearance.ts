import { Bot, Briefcase, Building2, Code2, Coffee, FlaskConical, Globe, GraduationCap, Heart, Home, Rocket, Shield, Sparkles, Terminal, User, Zap, type LucideIcon } from 'lucide-react';
import type { AccountColorName, AccountIconName } from '@/types/claudeAccounts';

export const ACCOUNT_ICONS: Record<AccountIconName, { icon: LucideIcon; label: string }> = {
    briefcase: { icon: Briefcase, label: 'Briefcase' },
    user: { icon: User, label: 'Person' },
    home: { icon: Home, label: 'Home' },
    building: { icon: Building2, label: 'Company' },
    flask: { icon: FlaskConical, label: 'Experiments' },
    code: { icon: Code2, label: 'Code' },
    rocket: { icon: Rocket, label: 'Rocket' },
    zap: { icon: Zap, label: 'Bolt' },
    sparkles: { icon: Sparkles, label: 'Sparkles' },
    shield: { icon: Shield, label: 'Shield' },
    bot: { icon: Bot, label: 'Robot' },
    terminal: { icon: Terminal, label: 'Terminal' },
    globe: { icon: Globe, label: 'Globe' },
    coffee: { icon: Coffee, label: 'Coffee' },
    school: { icon: GraduationCap, label: 'Learning' },
    heart: { icon: Heart, label: 'Heart' },
};

/** Every colour comes from the console-ui palette. Teal and plum mix two palette colours. */
export const ACCOUNT_COLORS: Record<AccountColorName, { label: string; fg: string; base: string }> = {
    violet: { label: 'Violet', fg: 'var(--accent-text)', base: 'var(--accent)' },
    blue: { label: 'Blue', fg: 'color-mix(in oklab, var(--info) 88%, var(--text))', base: 'var(--info)' },
    teal: { label: 'Teal', fg: 'color-mix(in oklab, color-mix(in oklab, var(--info) 55%, var(--ok)) 85%, var(--text))', base: 'color-mix(in oklab, var(--info) 55%, var(--ok))' },
    green: { label: 'Green', fg: 'color-mix(in oklab, var(--ok) 88%, var(--text))', base: 'var(--ok)' },
    amber: { label: 'Amber', fg: 'color-mix(in oklab, var(--warn) 85%, var(--text))', base: 'var(--warn)' },
    plum: { label: 'Plum', fg: 'color-mix(in oklab, color-mix(in oklab, var(--accent) 60%, var(--fail)) 85%, var(--text))', base: 'color-mix(in oklab, var(--accent) 60%, var(--fail))' },
    slate: { label: 'Slate', fg: 'var(--brand)', base: 'var(--brand)' },
};

export const KIND_LABEL = { setup_token: 'Setup token', api_key: 'API key' } as const;

export const TOKEN_PREFIX = { setup_token: 'sk-ant-oat01-', api_key: 'sk-ant-api03-' } as const;

export function modelOptions(models: string[]): { value: string; label: string }[] {
    return models.map((model) => ({ value: model, label: model.charAt(0).toUpperCase() + model.slice(1) }));
}

export function modelLabel(model: string | null): string {
    return model === null ? 'Default' : model.charAt(0).toUpperCase() + model.slice(1);
}

export function formatDate(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatAgo(iso: string | null): string {
    if (iso === null) {
        return 'never';
    }

    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    const units: [number, string][] = [[86400 * 7, 'week'], [86400, 'day'], [3600, 'hour'], [60, 'minute']];

    for (const [size, name] of units) {
        if (seconds >= size) {
            const count = Math.floor(seconds / size);

            return count === 1 && name === 'day' ? 'yesterday' : `${count} ${name}${count === 1 ? '' : 's'} ago`;
        }
    }

    return 'just now';
}

export function daysUntil(iso: string | null): number {
    return iso === null ? 0 : Math.max(0, Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000));
}
