import { Dialog } from '@geocodio/console-ui';
import { Maximize2 } from 'lucide-react';
import { useState } from 'react';
import type { Extension } from '@codemirror/state';
import { CodeEditor } from './CodeEditor';

/**
 * A CodeMirror field roughly 8 rows tall with a corner button that opens the
 * same editor in a large modal for comfortable editing. Both editors are
 * controlled by the same `value`/`onChange`, so edits made in either place
 * stay in sync -- the inline editor stays mounted behind the modal and picks
 * up the new value the moment it changes.
 */
export function ExpandableCodeField({
    value,
    onChange,
    languageExtensions,
    title,
    ariaLabel,
    'data-testid': dataTestId,
}: {
    value: string;
    onChange: (value: string) => void;
    /** Syntax highlighting extensions, forwarded to `CodeEditor`. Pass `[]` for plain prose. */
    languageExtensions?: Extension[];
    /** Dialog title and accessible name for the expand button. */
    title: string;
    ariaLabel: string;
    'data-testid'?: string;
}) {
    const [expanded, setExpanded] = useState(false);

    return (
        <div className="relative">
            <div className="h-48 overflow-hidden rounded-control border border-hair bg-panel">
                <CodeEditor value={value} onChange={onChange} languageExtensions={languageExtensions} ariaLabel={ariaLabel} data-testid={dataTestId} />
            </div>
            <button
                type="button"
                onClick={() => setExpanded(true)}
                className="absolute right-2 top-2 flex size-6 items-center justify-center rounded-control bg-panel text-faint shadow-card hover:text-body"
                aria-label={`Expand ${title}`}
            >
                <Maximize2 size={12} />
            </button>
            <Dialog open={expanded} onOpenChange={setExpanded} title={title} width="w-[min(92vw,960px)]">
                <div className="h-[80vh] overflow-hidden rounded-control border border-hair bg-panel">
                    <CodeEditor
                        value={value}
                        onChange={onChange}
                        languageExtensions={languageExtensions}
                        ariaLabel={`${ariaLabel} (expanded)`}
                        data-testid={dataTestId ? `${dataTestId}-expanded` : undefined}
                    />
                </div>
            </Dialog>
        </div>
    );
}
