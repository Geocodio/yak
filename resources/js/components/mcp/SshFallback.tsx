import { Button, cn } from '@geocodio/console-ui';
import { ChevronRight, Copy } from 'lucide-react';
import { useState } from 'react';

export function SshFallback({ sshHost }: { sshHost: string | null }) {
    const [open, setOpen] = useState(false);

    const prefix = sshHost ? `ssh -t root@${sshHost} ` : '';
    const commands = [
        `${prefix}yak-mcp login <name>`,
        `${prefix}yak-mcp list`,
        `${prefix}yak-mcp add --transport http <name> <url>`,
        `${prefix}yak-mcp remove <name>`,
    ];

    const copyAll = () => {
        navigator.clipboard.writeText(commands.join('\n'));
    };

    return (
        <div className="rounded-card border border-hair bg-panel shadow-card">
            <button
                type="button"
                className="flex w-full items-center gap-2 px-4 py-3 text-left"
                onClick={() => setOpen((o) => !o)}
                data-testid="mcp-ssh-toggle"
            >
                <ChevronRight size={14} className={cn('shrink-0 text-faint transition-transform', open && 'rotate-90')} />
                <div>
                    <div className="text-[13px] font-medium">Prefer the terminal?</div>
                    <div className="text-[12px] text-faint">Every action here has a one-line yak-mcp command for SSH.</div>
                </div>
            </button>

            {open && (
                <div className="border-t border-hair px-4 py-4">
                    <p className="text-[12.5px] leading-relaxed text-muted">
                        The <code className="font-mono">yak-mcp</code> helper on the server wraps the Claude CLI inside the Yak container, like{' '}
                        <code className="font-mono">yak-claude-login</code> does for the Claude Code login. It takes any{' '}
                        <code className="font-mono">claude mcp</code> subcommand.
                    </p>

                    <div className="mt-3 flex items-start gap-2">
                        <pre className="flex-1 overflow-x-auto rounded-control bg-panel-2 p-3 font-mono text-[12px] leading-relaxed">
                            {commands.join('\n')}
                        </pre>
                        <Button variant="tertiary" icon={<Copy size={13} />} onClick={copyAll} className="shrink-0">
                            Copy
                        </Button>
                    </div>

                    {!sshHost && <p className="mt-2 text-[12px] text-faint">Set YAK_SSH_HOST to show the full ssh command.</p>}
                </div>
            )}
        </div>
    );
}
