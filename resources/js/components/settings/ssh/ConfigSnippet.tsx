import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { Card } from '@/components/accounts/AccountsSection';

function useCopy(text: string) {
    const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');
    const copy = () => {
        const done = (next: 'copied' | 'failed') => {
            setState(next);
            window.setTimeout(() => setState('idle'), 1800);
        };

        if (!navigator.clipboard) {
            done('failed');

            return;
        }

        navigator.clipboard.writeText(text).then(() => done('copied'), () => done('failed'));
    };

    return { state, copy };
}

const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

export function ConfigSnippet({ command, snippet }: { command: string; snippet: string }) {
    const commandCopy = useCopy(command);
    const snippetCopy = useCopy(snippet);
    const label = snippetCopy.state === 'copied' ? 'Copied' : snippetCopy.state === 'failed' ? 'Copy failed, select the text' : 'Copy snippet';

    return (
        <section className="flex flex-col gap-3" aria-labelledby="ssh-config-heading">
            <div>
                <h3 id="ssh-config-heading" className="text-[14px] font-semibold">Set up your laptop</h3>
                <p className="mt-1 max-w-[560px] text-[12.5px] leading-relaxed text-muted">
                    Install the CLI, then run{' '}
                    <button type="button" onClick={commandCopy.copy} aria-label={`Copy ${command}`} className={`inline-flex items-center gap-1.5 rounded-control bg-panel-2 px-2 py-0.5 font-mono text-[12px] text-text ${FOCUS}`} data-testid="ssh-command-copy">
                        {command}
                        {commandCopy.state === 'copied' ? <Check size={12} aria-hidden="true" /> : <Copy size={12} aria-hidden="true" />}
                    </button>{' '}
                    to print this snippet for your own setup. Paste it into <code className="font-mono">~/.ssh/config</code> so plain <code className="font-mono">ssh</code>, <code className="font-mono">scp</code> and editor remote modes work with the jump host.
                </p>
            </div>
            <Card className="overflow-hidden" data-testid="ssh-config-snippet">
                <div className="flex items-center justify-between border-b border-hair bg-panel-2 px-3 py-2 text-[12px] text-muted">
                    <span className="font-mono">~/.ssh/config</span>
                    <button type="button" onClick={snippetCopy.copy} className={`inline-flex items-center gap-1.5 rounded-control hover:text-text ${FOCUS}`} data-testid="ssh-snippet-copy">
                        {label}
                        <Copy size={12} aria-hidden="true" />
                    </button>
                </div>
                <pre className="overflow-x-auto px-3 py-3 font-mono text-[12.5px] leading-relaxed">{snippet}</pre>
            </Card>
        </section>
    );
}
