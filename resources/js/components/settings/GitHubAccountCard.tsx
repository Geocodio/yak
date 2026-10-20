import { usePage } from "@inertiajs/react";
import { Badge, Button } from "@geocodio/console-ui";
import { Unlink } from "lucide-react";
import { useRouterAction } from "@/lib/useRouterAction";
import { connect, disconnect } from "@/routes/settings/github";
import type { ProfileData } from "@/types/settings";

function GitHubMark() {
    return (
        <svg
            width={14}
            height={14}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4" />
            <path d="M9 18c-4.51 2-5-2-7-2" />
        </svg>
    );
}

type Errors = {
    github?: string;
    github_taken?: string;
    github_taken_login?: string;
};

function LinkFailure({
    title,
    children,
    code,
}: {
    title: string;
    children: string;
    code?: string;
}) {
    return (
        <div
            className="mt-3 flex flex-wrap items-start justify-between gap-3 rounded-lg bg-fail/10 px-3.5 py-3"
            data-testid="github-link-error"
        >
            <div>
                <div className="text-[13px] font-semibold text-fail">
                    {title}
                </div>
                <p className="mt-0.5 text-[12.5px] leading-normal">
                    {children}
                </p>
                {code && (
                    <code className="font-mono text-[11px] text-muted">
                        {code}
                    </code>
                )}
            </div>
            <a href={connect.url()}>
                <Button>Try again</Button>
            </a>
        </div>
    );
}

export function GitHubAccountCard({
    github,
}: {
    github: ProfileData["github"];
}) {
    const action = useRouterAction();
    const errors = usePage().props.errors as Errors;
    const takenName = errors.github_taken;

    return (
        <div
            className="rounded-card border border-hair bg-panel p-4 shadow-card"
            data-testid="github-account-card"
        >
            <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
                <div className="max-w-md">
                    <div className="text-[13px] font-medium">
                        GitHub account
                    </div>
                    <div className="mt-0.5 text-[12px] leading-normal text-muted">
                        {github
                            ? "Yak uses this account to check your write access when you approve a change that needs a co-owner."
                            : "Connect GitHub to approve changes that need a co-owner. Yak reads only your GitHub username and checks your write access to each repository when you approve. It never acts on GitHub as you."}
                    </div>
                </div>
                {github ? (
                    <Button
                        pending={action.isPending("github-disconnect")}
                        pendingLabel="Disconnecting…"
                        onClick={() =>
                            action.run(
                                "github-disconnect",
                                "delete",
                                disconnect.url(),
                            )
                        }
                    >
                        <Unlink size={14} />
                        Disconnect
                    </Button>
                ) : (
                    <a href={connect.url()}>
                        <Button variant="primary">
                            <GitHubMark />
                            Connect GitHub
                        </Button>
                    </a>
                )}
            </div>

            {github && (
                <div className="mt-3 flex items-center gap-2.5 rounded-lg border border-hair bg-app px-3 py-2.5">
                    <span className="flex size-7 items-center justify-center rounded-full bg-panel2 text-[11px] font-semibold">
                        {github.login.slice(0, 2).toUpperCase()}
                    </span>
                    <div>
                        <span className="text-[13px] font-semibold">
                            {github.login}
                        </span>{" "}
                        <Badge tone="ok">Connected</Badge>
                        <div className="text-[12px] text-muted">
                            Linked on{" "}
                            {new Date(github.linkedAt).toLocaleDateString(
                                "en-GB",
                                { day: "numeric", month: "short" },
                            )}
                            . Access is checked live and cached for 10 minutes.
                        </div>
                    </div>
                </div>
            )}

            {!github && errors.github && (
                <LinkFailure
                    title="Could not link GitHub."
                    code={errors.github}
                >
                    GitHub sent you back without finishing the sign-in, so Yak
                    cannot check your access. Nothing was saved.
                </LinkFailure>
            )}

            {!github && takenName && (
                <LinkFailure title="This GitHub account is already linked.">
                    {`${errors.github_taken_login} is linked to ${takenName} in Yak. Each GitHub account can belong to one Yak user. Ask ${takenName.split(" ")[0]} to disconnect it on their profile, or sign in to GitHub with your own account.`}
                </LinkFailure>
            )}
        </div>
    );
}
