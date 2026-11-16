import { cn } from '@geocodio/console-ui';
import type { FilesMode } from './filesMode';

const OPTIONS: { id: FilesMode; label: string }[] = [
    { id: 'changed', label: 'Changed' },
    { id: 'everything', label: 'Everything' },
];

type Props = { mode: FilesMode; changedCount: number; onChange: (mode: FilesMode) => void };

/** The two-way switch at the top of the Files view's file tree. */
export function FilesModeSwitch({ mode, changedCount, onChange }: Props) {
    return (
        <div role="group" aria-label="Files shown" className="flex gap-0.5 rounded-[9px] bg-panel-2 p-0.5" data-testid="files-mode-switch">
            {OPTIONS.map((option) => (
                <button
                    key={option.id}
                    type="button"
                    aria-pressed={mode === option.id}
                    data-testid={`files-mode-${option.id}`}
                    onClick={() => onChange(option.id)}
                    className={cn('inline-flex h-7 flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-[7px] text-[13px]', mode === option.id ? 'bg-panel font-medium text-body shadow-[0_0_0_1px_var(--hair),0_1px_2px_rgb(0_0_0/0.1)]' : 'text-muted hover:text-body')}
                >
                    {option.label}
                    {option.id === 'changed' && changedCount > 0 && (
                        <span className="inline-grid h-[17px] min-w-[17px] place-items-center rounded-pill bg-app px-1.5 text-[11px] font-medium tabular-nums text-muted" data-testid="files-mode-count">
                            {changedCount}
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}
