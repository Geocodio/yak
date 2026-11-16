import { usePage } from '@inertiajs/react';
import { ExternalLink, GitPullRequest } from 'lucide-react';
import type { SharedProps } from '@/types/shared';

export function NoPullRequestsState({ expectations }: { expectations: string[] }) {
    const docsBaseUrl = usePage<SharedProps>().props.docs.baseUrl;

    return (
        <div className="flex flex-col items-center gap-3 px-2 py-8 text-center" data-testid="pull-requests-none">
            <GitPullRequest size={28} aria-hidden className="text-muted" />
            <h3 className="text-[14px] font-semibold text-body">No pull requests yet</h3>
            <p className="max-w-[320px] text-[13px] text-muted">
                When Claude finishes, Yak opens one pull request in each repo that changed. They link to each other and list the order to merge them in.
            </p>
            {expectations.length > 0 && (
                <div className="w-full rounded-card border border-hair bg-panel-2 px-3 py-2.5 text-left" data-testid="pull-requests-expectations">
                    <p className="text-[12.5px] font-semibold text-body">What to expect here</p>
                    <ul className="mt-1 flex flex-col gap-0.5">
                        {expectations.map((line) => (
                            <li key={line} className="font-mono text-[12.5px] text-muted">{line}</li>
                        ))}
                    </ul>
                </div>
            )}
            <a href={docsBaseUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-0.5 text-[12.5px] text-accent-text hover:underline">
                How pull requests work with several repos
                <ExternalLink size={10} aria-hidden />
            </a>
        </div>
    );
}
