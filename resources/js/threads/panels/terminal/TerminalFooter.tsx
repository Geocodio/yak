import { Link } from '@inertiajs/react';
import { toast } from '@geocodio/console-ui';
import { Copy } from 'lucide-react';
import { sshKeys } from '@/routes/settings';

export function TerminalFooter({ threadName }: { threadName: string }) {
    const command = `yak ssh ${threadName}`;
    const copy = () => {
        navigator.clipboard.writeText(command).then(
            () => toast.success('Copied'),
            () => toast.error('Could not copy the command.'),
        );
    };

    return (
        <div className="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-t border-hair px-4 py-2 text-[12px] text-muted" data-testid="terminal-footer">
            <span>The same shell as</span>
            <button type="button" onClick={copy} aria-label="Copy command" className="flex items-center gap-1.5 rounded border border-hair bg-app px-2 py-0.5 font-mono text-body">
                {command}
                <Copy size={11} />
            </button>
            <span>Closing a tab ends its shell.</span>
            <Link href={sshKeys().url} className="ml-auto underline" data-testid="terminal-keys-link">
                SSH keys
            </Link>
        </div>
    );
}
