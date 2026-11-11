import { cn } from '@geocodio/console-ui';
import type { AccountColorName, AccountIconName, Appearance } from '@/types/claudeAccounts';
import { ACCOUNT_COLORS, ACCOUNT_ICONS } from './accountAppearance';
import { AccountMark } from './AccountMark';

const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/** Icon and colour pickers for the add and edit dialogs. */
export function AppearanceFields({ value, onChange }: { value: Appearance; onChange: (next: Appearance) => void }) {
    return (
        <fieldset className="flex flex-col gap-3">
            <legend className="mb-1.5 text-[12.5px] font-medium">Icon and colour</legend>
            <div className="flex items-start gap-4">
                <AccountMark appearance={value} size={44} className="mt-0.5" />
                <div className="flex min-w-0 flex-1 flex-col gap-3">
                    <div role="radiogroup" aria-label="Icon" className="flex flex-wrap gap-1">
                        {(Object.keys(ACCOUNT_ICONS) as AccountIconName[]).map((key) => {
                            const Glyph = ACCOUNT_ICONS[key].icon;
                            const isOn = value.icon === key;

                            return (
                                <button key={key} type="button" role="radio" aria-checked={isOn} aria-label={ACCOUNT_ICONS[key].label} title={ACCOUNT_ICONS[key].label} onClick={() => onChange({ ...value, icon: key })} className={cn('grid size-8 place-items-center rounded-control border text-muted hover:bg-panel-2 hover:text-body', FOCUS, isOn ? 'border-accent bg-accent-soft text-accent-text' : 'border-hair')}>
                                    <Glyph size={15} />
                                </button>
                            );
                        })}
                    </div>
                    <div role="radiogroup" aria-label="Colour" className="flex flex-wrap gap-2">
                        {(Object.keys(ACCOUNT_COLORS) as AccountColorName[]).map((key) => {
                            const isOn = value.color === key;

                            return (
                                <button key={key} type="button" role="radio" aria-checked={isOn} aria-label={ACCOUNT_COLORS[key].label} title={ACCOUNT_COLORS[key].label} onClick={() => onChange({ ...value, color: key })} className={cn('grid size-6 place-items-center rounded-pill ring-offset-2 ring-offset-panel', FOCUS, isOn && 'ring-2 ring-accent')} style={{ background: ACCOUNT_COLORS[key].base }}>
                                    {isOn && <span className="size-1.5 rounded-pill bg-white/90" aria-hidden="true" />}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>
        </fieldset>
    );
}
