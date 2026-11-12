import { registerSidebarPanel } from '@/threads/panels';
import { PullRequestsPanel } from './PullRequestsPanel';

registerSidebarPanel({
    id: 'pull-requests',
    label: 'PRs',
    order: 20,
    component: PullRequestsPanel,
    // A single pull request stays on the Environment panel's pull request line.
    count: (thread) => thread.pullRequestCount,
    visible: (thread) => thread.repositories.length > 1 || thread.pullRequestCount > 1,
});
