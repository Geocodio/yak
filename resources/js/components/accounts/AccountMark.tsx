import { cn } from '@geocodio/console-ui';
import type { Appearance } from '@/types/claudeAccounts';
import { ACCOUNT_COLORS, ACCOUNT_ICONS } from './accountAppearance';

/** The account's own icon on a tint of its colour. */
export function AccountMark({ appearance, size = 28, className, dimmed }: { appearance: Appearance; size?: number; className?: string; dimmed?: boolean }) {
    const Glyph = ACCOUNT_ICONS[appearance.icon].icon;
    const color = ACCOUNT_COLORS[appearance.color];

    return (
        <span
            className={cn('grid shrink-0 place-items-center rounded-control', className)}
            style={{ width: size, height: size, color: color.fg, background: `color-mix(in oklab, ${color.base} 15%, transparent)`, opacity: dimmed ? 0.55 : 1, borderRadius: size <= 20 ? 5 : undefined }}
            aria-hidden="true"
        >
            <Glyph size={Math.round(size * 0.54)} />
        </span>
    );
}
