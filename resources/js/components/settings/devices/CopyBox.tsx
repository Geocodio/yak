import { Button } from '@geocodio/console-ui';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';

export function CopyBox({ command, label }: { command: string; label: string }) {
    const [didCopy, setDidCopy] = useState(false);
    const copy = () => {
        void navigator.clipboard?.writeText(command).then(() => {
            setDidCopy(true);
            window.setTimeout(() => setDidCopy(false), 1500);
        });
    };

    return (
        <div className="flex items-center justify-between gap-2 rounded-control border border-hair bg-panel-2 py-1.5 pl-3 pr-1.5">
            <span className="flex min-w-0 items-center gap-2 font-mono text-[12.5px]">
                <span className="text-muted" aria-hidden="true">$</span>
                <code className="min-w-0 truncate">{command}</code>
            </span>
            <Button variant="secondary" onClick={copy} aria-label={`Copy ${label}`} icon={didCopy ? <Check size={13} aria-hidden="true" /> : <Copy size={13} aria-hidden="true" />}>
                {didCopy ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}
