import { Bot, Briefcase, Building2, Code2, Coffee, FlaskConical, Globe, GraduationCap, Heart, Home, Rocket, Shield, Sparkles, Terminal, User, Zap, type LucideIcon } from 'lucide-react';
import type { AccountColorName, AccountIconName, EffortLevel, ModelFamily } from '@/types/claudeAccounts';

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

/** The family or older version behind a model value, or null when the catalog does not know it. */
export function findModel(models: ModelFamily[], model: string): { label: string; isPinned: boolean; efforts: EffortLevel[] } | null {
    for (const family of models) {
        if (family.latest.value === model) {
            return { label: family.latest.label, isPinned: false, efforts: family.latest.efforts ?? EFFORT_LEVELS };
        }

        const older = family.older.find((entry) => entry.value === model);

        if (older) {
            return { label: older.label, isPinned: true, efforts: older.efforts ?? EFFORT_LEVELS };
        }
    }

    return null;
}

export const EFFORT_LEVELS: EffortLevel[] = ['low', 'medium', 'high', 'xhigh', 'max'];

const EFFORT_NAMES: Record<EffortLevel, string> = { low: 'Low', medium: 'Medium', high: 'High', xhigh: 'XHigh', max: 'Max' };

/** The display name of an effort level; an unknown value keeps its raw text. */
export function effortLabel(level: string): string {
    return EFFORT_NAMES[level as EffortLevel] ?? level;
}

/** "Opus 5.5 · High", or just the model label when no effort is set. */
export function modelWithEffortLabel(model: string | null, effort: string | null, models: ModelFamily[]): string {
    return effort ? `${modelLabel(model, models)} · ${effortLabel(effort)}` : modelLabel(model, models);
}

/** A family alias reads as its current version, an exact id as that version, anything unknown as the raw value. */
export function modelLabel(model: string | null, models: ModelFamily[]): string {
    return model === null ? 'Default' : (findModel(models, model)?.label ?? model);
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
