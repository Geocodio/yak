import { ExternalLink } from 'lucide-react';
import { Card } from '@/components/accounts/AccountsSection';
import { TeleportSetup } from '@/components/teleport/TeleportSetup';
import { useDocsUrl } from '@/lib/docsUrl';

export function AddComputerCard({ heading }: { heading: string }) {
    const guideUrl = useDocsUrl('teleport/');

    return (
        <Card className="p-5" data-testid="add-computer">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h4 className="text-[14px] font-semibold">{heading}</h4>
                        <p className="mt-1 max-w-[480px] text-[12.5px] leading-relaxed text-muted">One command installs the plugin in Claude Code and signs this computer in.</p>
                    </div>
                    <a href={guideUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded-[3px] text-[12.5px] text-accent-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                        Install guide <ExternalLink size={12} aria-hidden="true" />
                    </a>
                </div>
                <div className="max-w-[520px]"><TeleportSetup alsoReload={['devices']} /></div>
            </div>
        </Card>
    );
}
