import { ExternalLink } from 'lucide-react';
import { Card } from '@/components/accounts/AccountsSection';
import { CopyBox } from './CopyBox';

export const INSTALL_GUIDE_URL = 'https://yak.build/docs/teleport';

export function AddComputerCard({ installCommand, loginCommand, heading }: { installCommand: string; loginCommand: string; heading: string }) {
    return (
        <Card className="p-5" data-testid="add-computer">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h4 className="text-[14px] font-semibold">{heading}</h4>
                        <p className="mt-1 max-w-[480px] text-[12.5px] leading-relaxed text-muted">Install the plugin in Claude Code, then sign in from the terminal on that computer.</p>
                    </div>
                    <a href={INSTALL_GUIDE_URL} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded-[3px] text-[12.5px] text-accent-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                        Install guide <ExternalLink size={12} aria-hidden="true" />
                    </a>
                </div>
                <ol className="flex max-w-[480px] flex-col gap-3 text-[12.5px] text-muted">
                    <li className="flex flex-col gap-1.5"><span>1. Install the plugin in Claude Code.</span><CopyBox command={installCommand} label="install command" /></li>
                    <li className="flex flex-col gap-1.5"><span>2. Sign in from your terminal and enter the code it shows.</span><CopyBox command={loginCommand} label="login command" /></li>
                </ol>
            </div>
        </Card>
    );
}
