import { Badge, Button } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { store as storeMessage } from '@/routes/threads/messages';
import { parseEnvironmentNote } from './environmentNote';

const SETUP_REQUEST = 'Add the installs from the environment note to setup.sh.';

/** The note Claude is given when the thread changes side, folded to one line until opened. */
export function EnvironmentNoteCard({ threadId, text, from, to, variant = 'teleport', canDrive = false }: { threadId: number; text: string; from: string; to: string; variant?: 'teleport' | 'restore'; canDrive?: boolean }) {
    const [open, setOpen] = useState(false);
    const { rows, raw } = parseEnvironmentNote(text);

    if (rows.length === 0) {
        return null;
    }

    const summary = rows.map((row) => row.heading).join(', ');

    return (
        <section className="rounded-card border border-hair bg-panel-2 p-3 text-[12px]" data-testid="environment-note">
            <div className="flex items-center gap-2">
                <span className="font-medium text-body">{variant === 'restore' ? 'Sandbox rebuilt' : `Environment changed: ${from} to ${to}`}</span>
                <Badge tone="neutral">Claude was told too</Badge>
                <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="ml-auto inline-flex cursor-pointer items-center gap-1 text-muted hover:text-body" data-testid="environment-note-toggle">
                    <ChevronRight size={12} className={open ? 'rotate-90' : ''} />
                    {open ? 'Hide' : 'Show'}
                </button>
            </div>
            {!open && <p className="mt-1 truncate text-muted">{summary}</p>}
            {open && (
                <div className="mt-2 flex flex-col gap-2" data-testid="environment-note-rows">
                    {rows.map((row) => (
                        <div key={row.heading}>
                            <div className="text-muted">{row.heading}</div>
                            <ul className="mt-0.5 list-disc pl-4 text-body">
                                {row.items.map((item) => (
                                    <li key={item}>{item}</li>
                                ))}
                            </ul>
                        </div>
                    ))}
                    <details>
                        <summary className="cursor-pointer text-muted">See what Claude was told</summary>
                        <pre className="mt-1 overflow-auto whitespace-pre-wrap rounded-card bg-app p-2 font-mono text-[11px]">{raw}</pre>
                    </details>
                    {variant === 'restore' && canDrive && (
                        <Button variant="secondary" onClick={() => router.post(storeMessage.url(threadId), { message: SETUP_REQUEST }, { preserveScroll: true })}>
                            Ask Claude to add these installs to setup.sh
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}
