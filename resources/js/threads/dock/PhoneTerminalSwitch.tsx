import { cn } from '@geocodio/console-ui';
import type { PhoneTerminalMode } from './dockState';

type Props = { mode: PhoneTerminalMode; onChange: (mode: PhoneTerminalMode) => void };

const MODES: { id: PhoneTerminalMode; label: string }[] = [
    { id: 'shell', label: 'Shell' },
    { id: 'files', label: 'Files' },
];

/** Segmented Shell / Files switch on the phone Terminal tab; desktop has a dock toggle for each instead. */
export function PhoneTerminalSwitch({ mode, onChange }: Props) {
    return (
        <div className="flex shrink-0 justify-center border-b border-hair px-4 py-2 lg:hidden" data-testid="phone-terminal-switch">
            <div role="group" aria-label="Terminal view" className="flex gap-px rounded-chip border border-hair bg-hair">
                {MODES.map((item) => (
                    <button
                        key={item.id}
                        type="button"
                        aria-pressed={item.id === mode}
                        data-testid={`phone-terminal-mode-${item.id}`}
                        onClick={() => onChange(item.id)}
                        className={cn('cursor-pointer bg-app px-4 py-1 text-[12.5px]', item.id === mode ? 'font-medium text-body' : 'text-muted')}
                    >
                        {item.label}
                    </button>
                ))}
            </div>
        </div>
    );
}
