import { Badge } from '@geocodio/console-ui';
import { GitBranch, GitPullRequest, Globe } from 'lucide-react';
import { CopyButton, OpenLink } from '@/components/threads/environment/LinkActions';
import type { PanelProps } from '@/threads/panels';
import { PullRequestsPanel } from '@/threads/panels/pull-requests/PullRequestsPanel';
import type { ThreadEnvironment } from '@/types/thread-changes';
import { OverviewGroup, OverviewList, OverviewRow, RowNote } from './OverviewRow';
import { RepositoryRows } from './RepositoryRows';

const PULL_REQUEST_TONE: Record<string, 'ok' | 'accent' | 'neutral'> = { open: 'ok', merged: 'accent', closed: 'neutral' };

function capitalize(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

const LINK_CLASS = 'inline-flex min-w-0 flex-1 items-center gap-1.5 rounded-[6px] px-1.5 py-0.5 -ml-1.5 text-accent-text no-underline hover:bg-panel-2 hover:underline';

/** Preview, branch, pull request and deployment, each with copy and open where they apply. */
export function LinksGroup({ thread, canDrive, environment, onChanged, onOpenChanges }: PanelProps & { environment: ThreadEnvironment; onChanged: () => void }) {
    const { branch, pullRequest, deployment, repositories } = environment;
    const previewUrl = thread.preview.host === null ? null : `https://${thread.preview.host}`;
    const hasSeveralPullRequests = thread.repositories.length > 1 || thread.pullRequestCount > 1;
    const branchUrl = branch !== null && thread.repo.includes('/') ? `https://github.com/${thread.repo}/tree/${branch}` : null;

    return (
        <OverviewGroup title="Links" testId="overview-links">
            <OverviewList>
                {previewUrl !== null && (
                    <OverviewRow label="Preview" testId="environment-preview">
                        <a href={previewUrl} target="_blank" rel="noopener noreferrer" className={LINK_CLASS} data-testid="environment-preview-link">
                            <Globe size={12} className="shrink-0" aria-hidden />
                            <code className="truncate font-mono text-[12px]">{thread.preview.host}</code>
                        </a>
                        <CopyButton text={previewUrl} label="preview URL" testId="environment-preview-copy" />
                        <OpenLink href={previewUrl} label="the preview" testId="environment-preview-open" />
                        {thread.preview.state === 'ready' && (
                            <RowNote>
                                <span className="inline-block size-1.5 rounded-pill bg-ok" aria-hidden />
                                Dev server running
                            </RowNote>
                        )}
                    </OverviewRow>
                )}

                <OverviewRow label="Branch" testId="environment-branch">
                    {branch === null ? (
                        <span className="text-muted">Not created yet</span>
                    ) : (
                        <>
                            <GitBranch size={12} className="shrink-0 text-muted" aria-hidden />
                            <code className="min-w-0 flex-1 truncate font-mono text-[12px]">{branch}</code>
                            <CopyButton text={branch} label="branch name" />
                            {branchUrl !== null && <OpenLink href={branchUrl} label="the branch on GitHub" />}
                        </>
                    )}
                </OverviewRow>

                <OverviewRow label="Pull request" testId="environment-pull-request">
                    {pullRequest === null ? (
                        <>
                            <span className="grow text-muted">Not opened yet</span>
                            {onOpenChanges !== undefined && (
                                <button type="button" onClick={() => onOpenChanges()} className="cursor-pointer text-[12.5px] text-accent-text hover:underline" data-testid="environment-review-changes">
                                    Review changes
                                </button>
                            )}
                        </>
                    ) : (
                        <>
                            <a href={pullRequest.url} target="_blank" rel="noopener noreferrer" className={LINK_CLASS} data-testid="environment-pull-request-link">
                                <GitPullRequest size={12} className="shrink-0" aria-hidden />#{pullRequest.number}
                            </a>
                            <Badge tone={PULL_REQUEST_TONE[pullRequest.state] ?? 'neutral'}>{capitalize(pullRequest.state)}</Badge>
                            <CopyButton text={pullRequest.url} label="pull request link" />
                            <OpenLink href={pullRequest.url} label={`pull request #${pullRequest.number}`} />
                        </>
                    )}
                </OverviewRow>

                <OverviewRow label="Deployment" testId="environment-deployment" tip="The preview served from this thread's branch.">
                    {deployment === null ? (
                        <span className="text-muted">None for this branch</span>
                    ) : (
                        <>
                            <a href={deployment.url} target="_blank" rel="noopener noreferrer" className={LINK_CLASS}>
                                <code className="truncate font-mono text-[12px]">{hostOf(deployment.url)}</code>
                            </a>
                            <Badge tone="neutral">{deployment.status}</Badge>
                            <CopyButton text={deployment.url} label="deployment URL" />
                            <OpenLink href={deployment.url} label="the deployment" />
                        </>
                    )}
                </OverviewRow>
            </OverviewList>

            {hasSeveralPullRequests && (
                <div className="mt-4 border-t border-hair pt-3" data-testid="overview-pull-requests">
                    <PullRequestsPanel thread={thread} canDrive={canDrive} />
                </div>
            )}

            {repositories.length > 1 && (
                <section className="mt-4 border-t border-hair pt-3" data-testid="environment-repositories">
                    <h4 className="mb-1 text-[12px] text-muted">Repositories</h4>
                    <RepositoryRows threadId={thread.id} repositories={repositories} canDrive={canDrive} onChanged={onChanged} />
                </section>
            )}
        </OverviewGroup>
    );
}
