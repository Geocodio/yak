import { ChevronsUpDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export function FontPicker({
    role,
    label,
    value,
    options,
    onChange,
}: {
    role: string;
    label: string;
    value: string;
    options: string[];
    onChange: (family: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const rootRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onClickOutside = (event: MouseEvent) => {
            if (rootRef.current && !rootRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const onEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('click', onClickOutside);
        window.addEventListener('keydown', onEscape);

        return () => {
            document.removeEventListener('click', onClickOutside);
            window.removeEventListener('keydown', onEscape);
        };
    }, [open]);

    return (
        <div className="relative" ref={rootRef}>
            <label className="text-[12px] font-medium text-muted">{label}</label>
            <button
                type="button"
                data-testid={`font-picker-${role}`}
                className="mt-1 flex h-8 w-full items-center justify-between rounded-control border border-hair bg-panel px-3 text-left text-[13px] text-ink shadow-xs hover:border-hair-strong"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                aria-haspopup="listbox"
            >
                <span className="truncate" style={{ fontFamily: `'${value}', sans-serif` }}>
                    {value}
                </span>
                <span className="ml-3 flex shrink-0 items-center gap-2 text-faint">
                    <span className="text-[11px]" style={{ fontFamily: `'${value}', sans-serif` }}>
                        Aa Bb 123
                    </span>
                    <ChevronsUpDown size={14} />
                </span>
            </button>
            {open && (
                <ul
                    role="listbox"
                    aria-label={label}
                    className="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-control border border-hair bg-panel py-1 shadow-overlay"
                >
                    {options.map((family) => (
                        <li
                            key={family}
                            role="option"
                            aria-selected={family === value}
                            onClick={() => {
                                onChange(family);
                                setOpen(false);
                            }}
                            className={`flex cursor-pointer items-center justify-between gap-4 px-3 py-2 text-[13px] hover:bg-panel-2 ${
                                family === value ? 'bg-accent-soft text-accent-text' : 'text-ink'
                            }`}
                        >
                            <span style={{ fontFamily: `'${family}', sans-serif` }}>{family}</span>
                            <span className="shrink-0 text-faint" style={{ fontFamily: `'${family}', sans-serif` }}>
                                Aa Bb 123
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
