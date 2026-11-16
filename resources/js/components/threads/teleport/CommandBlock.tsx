import { Button, toast } from '@geocodio/console-ui';
import { Copy } from 'lucide-react';

/** A command in code style with a copy button. */
export function CommandBlock({ command, testId, prefix = '$' }: { command: string; testId: string; prefix?: string }) {
    const copy = () => {
        navigator.clipboard.writeText(command).then(
            () => toast.success('Copied'),
            () => toast.error('Could not copy the command.'),
        );
    };

    return (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-hair bg-app px-3 py-2">
            <span className="min-w-0 break-all font-mono text-[13px] text-body">
                {prefix && (
                    <span className="select-none text-faint" aria-hidden="true">
                        {prefix}{' '}
                    </span>
                )}
                <code data-testid={testId}>{command}</code>
            </span>
            <Button variant="secondary" icon={<Copy size={13} />} onClick={copy} aria-label="Copy command">
                Copy
            </Button>
        </div>
    );
}
