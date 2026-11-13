import { cn, toast } from '@geocodio/console-ui';
import { Check, Copy, ExternalLink } from 'lucide-react';
import { useState } from 'react';

const BUTTON_CLASS = 'inline-flex size-[26px] shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-muted hover:bg-panel-2 hover:text-body';

/** A small icon button that copies `text`, with the label naming what was copied ("preview URL"). */
export function CopyButton({ text, label, className, testId }: { text: string; label: string; className?: string; testId?: string }) {
    const [isCopied, setIsCopied] = useState(false);

    const copy = () => {
        navigator.clipboard.writeText(text).then(
            () => {
                setIsCopied(true);
                toast.success(`Copied the ${label}`);
                window.setTimeout(() => setIsCopied(false), 1800);
            },
            () => toast.error(`Could not copy the ${label}.`),
        );
    };

    return (
        <button type="button" onClick={copy} aria-label={`Copy ${label}`} title={`Copy ${label}`} className={cn(BUTTON_CLASS, isCopied && 'text-ok', className)} data-testid={testId}>
            {isCopied ? <Check size={13} aria-hidden /> : <Copy size={13} aria-hidden />}
        </button>
    );
}

/** A small icon link that opens `href` in a new tab. */
export function OpenLink({ href, label, className, testId }: { href: string; label: string; className?: string; testId?: string }) {
    return (
        <a href={href} target="_blank" rel="noopener noreferrer" aria-label={`Open ${label} in a new tab`} title="Open in a new tab" className={cn(BUTTON_CLASS, className)} data-testid={testId}>
            <ExternalLink size={13} aria-hidden />
        </a>
    );
}
