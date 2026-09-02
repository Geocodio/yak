import { Button, TextInput } from '@geocodio/console-ui';
import { X } from 'lucide-react';
import { useState } from 'react';

const GLOB_PATTERN = /^[A-Za-z0-9_./*?-]+$/;

/**
 * Client-side chip editor for PR review path filters. `value === null` means
 * the repository uses the global defaults (shown, not editable, from
 * `defaults`); adding, removing, or resetting flips between the two states.
 */
export function PathExcludes({
    value,
    defaults,
    onChange,
}: {
    value: string[] | null;
    defaults: string[];
    onChange: (value: string[] | null) => void;
}) {
    const [input, setInput] = useState('');
    const [error, setError] = useState<string | null>(null);
    const usingDefaults = value === null;
    const patterns = usingDefaults ? defaults : value;

    const addPattern = () => {
        const pattern = input.trim();

        if (pattern === '' || patterns.includes(pattern)) {
            return;
        }

        if (!GLOB_PATTERN.test(pattern)) {
            setError('Invalid glob pattern.');
            return;
        }

        setError(null);
        onChange([...(usingDefaults ? [] : value), pattern]);
        setInput('');
    };

    const removePattern = (index: number) => {
        if (usingDefaults) {
            return;
        }
        onChange(value.filter((_, i) => i !== index));
    };

    return (
        <div className="ml-4 rounded-card border border-hair bg-panel px-4 py-3 shadow-card">
            <div className="flex items-center justify-between">
                <div className="text-[13px] font-medium">PR review path filters</div>
                {!usingDefaults && (
                    <Button variant="link" className="text-[12px]" onClick={() => onChange(null)}>
                        Reset to defaults
                    </Button>
                )}
            </div>
            {usingDefaults && <p className="mt-1 text-[12px] text-muted">Using global defaults.</p>}
            <div className="mt-2 flex flex-wrap gap-1.5">
                {patterns.map((pattern, index) => (
                    <span key={pattern} className="flex items-center gap-1 rounded-chip border border-hair bg-panel-2 px-1.5 py-0.5 font-mono text-[11px]">
                        {pattern}
                        {!usingDefaults && (
                            <button type="button" className="text-faint hover:text-body" onClick={() => removePattern(index)} aria-label={`Remove ${pattern}`}>
                                <X size={10} />
                            </button>
                        )}
                    </span>
                ))}
            </div>
            <div className="mt-2 flex items-center gap-2">
                <TextInput
                    placeholder="vendor/**"
                    className="max-w-[260px] font-mono text-[12px]"
                    value={input}
                    onChange={(event) => setInput(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            addPattern();
                        }
                    }}
                />
                <Button onClick={addPattern}>Add pattern</Button>
            </div>
            {error && <p className="mt-1 text-[12px] text-fail">{error}</p>}
        </div>
    );
}
