import { Popover } from '@base-ui/react/popover';
import { Check, ChevronDown, ChevronRight } from 'lucide-react';
import { useRef, useState, type KeyboardEvent } from 'react';
import type { ModelFamily } from '@/types/claudeAccounts';
import { findModel } from './accountAppearance';

type Props = {
    models: ModelFamily[];
    /** A family alias, an exact model id, or '' / null for "no explicit choice". */
    value: string | null;
    onChange: (value: string) => void;
    /** Adds a first row that selects '' and is shown while `value` is empty, e.g. "Account default (Opus 5.5)". */
    defaultLabel?: string;
    disabled?: boolean;
    ariaLabel?: string;
    /** `field` is a bordered control for forms, `inline` is plain text for toolbars and table rows. */
    variant?: 'field' | 'inline';
    className?: string;
    testId?: string;
};

const ROW_BASE = 'flex min-w-0 flex-1 items-start gap-2 rounded-control px-2 py-1.5 text-left outline-none hover:bg-panel-2 focus-visible:bg-panel-2';
const SELECTED = 'bg-[var(--accent-soft)]';

/**
 * The one place a model is chosen. A family row selects the family alias, which follows the newest
 * version; its chevron opens the older versions, and a sub-row pins that exact id.
 */
export function ModelPicker({ models, value, onChange, defaultLabel, disabled, ariaLabel = 'Model', variant = 'field', className = '', testId = 'model-picker' }: Props) {
    const pinnedFamily = (current: string | null) => models.find((family) => family.older.some((entry) => entry.value === current))?.family ?? null;
    const [isOpen, setIsOpen] = useState(false);
    const [expanded, setExpanded] = useState<string | null>(pinnedFamily(value));
    const menuRef = useRef<HTMLDivElement>(null);

    if (models.length === 0) {
        return null;
    }

    const found = value ? findModel(models, value) : null;
    const triggerLabel = !value && defaultLabel ? defaultLabel : (found?.label ?? value ?? 'Model');
    const isPinned = found?.isPinned ?? false;

    const pick = (next: string) => {
        onChange(next);
        setIsOpen(false);
    };

    const rows = () => Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[data-model-row]') ?? []);

    const focusRow = (family: string) => {
        requestAnimationFrame(() => rows().find((row) => row.dataset.modelRow === family)?.focus());
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const all = rows();
        const index = all.indexOf(document.activeElement as HTMLElement);
        const current = index >= 0 ? all[index] : null;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = event.key === 'ArrowDown' ? index + 1 : index - 1;
            all[(next + all.length) % all.length]?.focus();
        } else if (event.key === 'ArrowRight' && current?.dataset.expandable === 'true') {
            event.preventDefault();
            setExpanded(current.dataset.family ?? null);
        } else if (event.key === 'ArrowLeft' && current?.dataset.family) {
            event.preventDefault();
            setExpanded(null);
            focusRow(current.dataset.family);
        }
    };

    const triggerClass = variant === 'field'
        ? 'flex h-9 w-full items-center justify-between gap-2 rounded-control border border-hair-strong bg-panel px-3 text-[13px] text-body shadow-card hover:border-faint disabled:opacity-50'
        : 'inline-flex items-center gap-1 text-[12px] text-muted disabled:opacity-50';

    return (
        <Popover.Root open={isOpen} onOpenChange={(open) => { setIsOpen(open); if (open) { setExpanded(pinnedFamily(value)); } }}>
            <Popover.Trigger disabled={disabled} aria-label={ariaLabel} className={`${triggerClass} ${className}`} data-testid={testId}>
                <span className="truncate">
                    {triggerLabel}
                    {isPinned && <span className="text-muted"> · pinned</span>}
                </span>
                <ChevronDown size={12} className="shrink-0 text-faint" aria-hidden="true" />
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Positioner align="start" sideOffset={4} className="z-50 outline-none">
                    <Popover.Popup className="ui-floating w-[320px] max-w-[calc(100vw-24px)] rounded-card border border-hair bg-panel p-1 shadow-overlay outline-none" data-testid={`${testId}-menu`}>
                        <div ref={menuRef} role="menu" aria-label={ariaLabel} onKeyDown={onKeyDown}>
                            {defaultLabel && (
                                <button type="button" role="menuitemradio" aria-checked={!value} data-model-row="default" onClick={() => pick('')} className={`${ROW_BASE} w-full ${!value ? SELECTED : ''}`} data-testid={`${testId}-default`}>
                                    <Check size={12} className={`mt-[3px] shrink-0 ${value ? 'invisible' : ''}`} aria-hidden="true" />
                                    <span className="text-[13px] font-medium text-body">{defaultLabel}</span>
                                </button>
                            )}
                            {models.map((family) => {
                                const isOpenFamily = expanded === family.family;
                                const isSelected = value === family.latest.value;
                                const hasOlder = family.older.length > 0;

                                return (
                                    <div key={family.family} role="none">
                                        <div className="flex items-start">
                                            <button type="button" role="menuitemradio" aria-checked={isSelected} data-model-row={family.family} data-family={family.family} data-expandable={hasOlder} onClick={() => pick(family.latest.value)} className={`${ROW_BASE} ${isSelected ? SELECTED : ''}`} data-testid={`${testId}-${family.latest.value}`}>
                                                <Check size={12} className={`mt-[3px] shrink-0 ${isSelected ? '' : 'invisible'}`} aria-hidden="true" />
                                                <span className="min-w-0">
                                                    <span className="block text-[13px] font-medium text-body">{family.latest.label}</span>
                                                    <span className="block text-[12px] text-muted">{family.latest.description}</span>
                                                </span>
                                            </button>
                                            {hasOlder && (
                                                <button type="button" tabIndex={-1} aria-expanded={isOpenFamily} aria-label={`Older ${family.family} versions`} onClick={() => setExpanded(isOpenFamily ? null : family.family)} className="ml-1 mt-1 inline-flex size-7 shrink-0 items-center justify-center rounded-control text-muted outline-none hover:bg-panel-2 focus-visible:bg-panel-2" data-testid={`${testId}-expand-${family.family}`}>
                                                    {isOpenFamily ? <ChevronDown size={14} aria-hidden="true" /> : <ChevronRight size={14} aria-hidden="true" />}
                                                </button>
                                            )}
                                        </div>
                                        {hasOlder && isOpenFamily && (
                                            <div className="ml-4 border-l border-hair pl-1" role="none">
                                                {family.older.map((entry) => {
                                                    const isPinnedRow = value === entry.value;

                                                    return (
                                                        <button key={entry.value} type="button" role="menuitemradio" aria-checked={isPinnedRow} data-model-row={entry.value} data-family={family.family} onClick={() => pick(entry.value)} className={`${ROW_BASE} w-full items-center ${isPinnedRow ? SELECTED : ''}`} data-testid={`${testId}-${entry.value}`}>
                                                            <Check size={12} className={`shrink-0 ${isPinnedRow ? '' : 'invisible'}`} aria-hidden="true" />
                                                            <span className="text-[13px] text-body">{entry.label}</span>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </Popover.Popup>
                </Popover.Positioner>
            </Popover.Portal>
        </Popover.Root>
    );
}
