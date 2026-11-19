import { OverviewGroup } from './OverviewRow';

/** What the Overview shows before a repository is picked: the sandbox and the links start after the answer. */
export function NoRepositoryGroups() {
    return (
        <>
            <OverviewGroup title="Sandbox" testId="overview-no-sandbox">
                <p className="text-[13px] text-muted">No sandbox yet. Yak creates one when the repository is known.</p>
            </OverviewGroup>
            <OverviewGroup title="Links" testId="overview-no-links">
                <p className="text-[13px] text-muted">Preview, branch and pull request appear here once work starts.</p>
            </OverviewGroup>
        </>
    );
}
