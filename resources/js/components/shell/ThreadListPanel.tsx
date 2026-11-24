import { usePage } from "@inertiajs/react";
import { threadIdOfUrl } from "@/components/shell/threadUrl";
import {
    Button,
    IconButton,
    Skeleton,
    Tooltip,
    cn,
} from "@geocodio/console-ui";
import { Link } from "@inertiajs/react";
import { Fragment } from "react";
import { edit as editProfile } from "@/routes/profile";
import { PanelLeftClose, Plus, Search, X } from "lucide-react";
import { shortcutLabel } from "@/commandPalette/shortcuts";
import { isMacPlatform } from "@/commandPalette/platform";
import { ThreadFilterMenu, filterKeyLabel, starterLabel } from "@/components/shell/ThreadFilterMenu";
import { ThreadGroup } from "@/components/shell/ThreadGroup";
import { useKeepThreadSeen } from "@/components/shell/useMarkThreadSeen";
import { useThreadList } from "@/components/shell/useThreadList";
import { openNewThread, openPalette } from "@/components/Sidebar";
import type { SharedProps } from "@/types/shared";
import type { ThreadListFilter } from "@/types/shell";

const FILTERS: { value: ThreadListFilter; label: string }[] = [
    { value: "mine", label: "Mine" },
    { value: "review", label: "Review" },
    { value: "everyone", label: "Everyone" },
    { value: "closed", label: "Closed" },
];

export function ThreadListPanel({
    onCollapse,
    touch = false,
}: {
    onCollapse?: () => void;
    touch?: boolean;
}) {
    const { props, url } = usePage<SharedProps>();
    const {
        data,
        filter,
        setFilter,
        sources,
        setSources,
        starters,
        setStarters,
        clearFilters,
        isLoading,
    } = useThreadList(props.threadList);
    const viewerStarterKey = props.auth.user
        ? `user:${props.auth.user.id}`
        : null;
    const hasFilters = sources.length > 0 || starters.length > 0;
    const chips = [
        ...sources.map((key) => ({
            key: `source:${key}`,
            label:
                data?.facets.sources.find((option) => option.key === key)
                    ?.label ?? filterKeyLabel(key),
            remove: () => setSources(sources.filter((item) => item !== key)),
        })),
        ...starters.map((key) => ({
            key: `starter:${key}`,
            label: starterLabel(
                key,
                data?.facets.starters.find((option) => option.key === key)
                    ?.name ?? filterKeyLabel(key),
                viewerStarterKey,
            ),
            remove: () =>
                setStarters(starters.filter((item) => item !== key)),
        })),
    ];
    const activeThreadId = threadIdOfUrl(url);
    useKeepThreadSeen(activeThreadId, data);
    const hasNoThreadsAtAll =
        data !== null && !data.hasAnyThreads;
    const isEmpty =
        data !== null &&
        data.starred.length === 0 &&
        data.needsYou.length === 0 &&
        data.threads.length === 0 &&
        (data.review === undefined ||
            (!data.review.needsGitHubLink &&
                data.review.backToYou.length +
                    data.review.notReviewed.length +
                    data.review.withYak.length +
                    data.review.approved.length ===
                    0));
    const rowsOf = (group: "today" | "this_week" | "older") =>
        (data?.threads ?? []).filter((row) => row.group === group);

    return (
        <div
            className="flex min-h-0 flex-1 flex-col"
            data-testid="thread-list-panel"
        >
            <div className="flex h-12 items-center justify-between px-3">
                <span className="text-[13.5px] font-semibold tracking-tight">
                    Threads
                </span>
                {onCollapse ? (
                    <IconButton
                        label="Hide threads"
                        onClick={onCollapse}
                        data-testid="collapse-thread-list"
                    >
                        <PanelLeftClose size={14} />
                    </IconButton>
                ) : null}
            </div>
            <div className="flex flex-col gap-2 px-3 pb-2">
                <div className="flex gap-2">
                    <Button
                        variant="primary"
                        icon={<Plus size={14} />}
                        onClick={() => openNewThread()}
                        className={cn("min-w-0 flex-1", touch && "h-10")}
                        data-testid="new-thread"
                    >
                        New thread
                    </Button>
                    <Tooltip
                        label={`Search ${shortcutLabel("openPalette", isMacPlatform())}`}
                    >
                        <Button
                            variant="secondary"
                            icon={<Search size={14} />}
                            onClick={openPalette}
                            aria-label="Search"
                            className={cn(
                                "w-8 shrink-0 px-0",
                                touch && "h-10 w-10",
                            )}
                            data-testid="thread-search-button"
                        />
                    </Tooltip>
                </div>
                {hasNoThreadsAtAll ? (
                    <p
                        className="px-1 pt-1 text-[12px] text-muted"
                        data-testid="thread-list-quiet-empty"
                    >
                        Your threads show up here. Filters appear once you have
                        a few.
                    </p>
                ) : (
                    <>
                        <div
                            className="flex min-w-0 items-center justify-between gap-1.5"
                            data-testid="thread-filter"
                        >
                            <div
                                role="group"
                                aria-label="Thread filter"
                                className="flex rounded-control bg-panel-2 p-0.5"
                            >
                                {FILTERS.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={filter === option.value}
                                        data-testid={`thread-filter-${option.value}`}
                                        onClick={() => setFilter(option.value)}
                                        className={cn(
                                            "rounded-control px-2 py-0.5 text-[12px] text-muted",
                                            filter === option.value &&
                                                "bg-panel font-medium text-body shadow-card",
                                        )}
                                    >
                                        {option.label}
                                        {option.value === "review" &&
                                        data !== null &&
                                        data.reviewCount > 0 ? (
                                            <span className="tnum ml-1 rounded-pill bg-brand-soft px-1.5 text-[10.5px] text-brand">
                                                {data.reviewCount}
                                            </span>
                                        ) : null}
                                    </button>
                                ))}
                            </div>
                            <ThreadFilterMenu
                                facets={data?.facets ?? null}
                                sources={sources}
                                starters={starters}
                                onSourcesChange={setSources}
                                onStartersChange={setStarters}
                                viewerStarterKey={viewerStarterKey}
                            />
                        </div>
                        {hasFilters ? (
                            <div
                                className="flex items-start justify-between gap-2"
                                data-testid="thread-filter-chips"
                            >
                                <div className="flex flex-wrap gap-1">
                                    {chips.map((chip) => (
                                        <span
                                            key={chip.key}
                                            className="inline-flex items-center gap-1 rounded-pill border border-hair bg-panel py-0.5 pl-2 pr-1 text-[12px]"
                                        >
                                            {chip.label}
                                            <button
                                                type="button"
                                                aria-label={`Remove ${chip.label} filter`}
                                                onClick={chip.remove}
                                                className="flex rounded-pill p-0.5 text-muted hover:text-body"
                                            >
                                                <X size={11} />
                                            </button>
                                        </span>
                                    ))}
                                </div>
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="shrink-0 py-0.5 text-[12px] text-muted hover:text-body"
                                    data-testid="thread-filter-clear"
                                >
                                    Clear
                                </button>
                            </div>
                        ) : null}
                    </>
                )}
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                {data === null || (isLoading && isEmpty) ? (
                    <div
                        className="flex flex-col gap-2 px-1 pt-3"
                        data-testid="thread-list-loading"
                    >
                        {[0, 1, 2, 3, 4].map((index) => (
                            <Skeleton key={index} className="h-9 w-full" />
                        ))}
                    </div>
                ) : hasNoThreadsAtAll ? null : filter === "review" &&
                  data.review?.needsGitHubLink ? (
                    <div
                        className="mt-3 rounded-card border border-dashed border-hair p-4 text-center"
                        data-testid="review-link-github-prompt"
                    >
                        <div className="text-[13px] font-medium">
                            Link your GitHub account
                        </div>
                        <p className="mt-1 text-[12px] text-muted">
                            Yak matches your reviews to threads by your GitHub
                            login.
                        </p>
                        <Link
                            href={editProfile.url()}
                            data-testid="review-link-github"
                            className="mt-2 inline-block text-[12px] font-medium text-brand hover:underline"
                        >
                            Link GitHub in settings
                        </Link>
                    </div>
                ) : isEmpty ? (
                    <div
                        className="mt-3 rounded-card border border-dashed border-hair p-4 text-center"
                        data-testid="thread-list-empty"
                    >
                        <div className="text-[13px] font-medium">
                            {hasFilters
                                ? "No threads match these filters"
                                : "No threads here"}
                        </div>
                        {hasFilters ? (
                            <Button
                                variant="secondary"
                                onClick={clearFilters}
                                className="mt-2"
                            >
                                Clear filters
                            </Button>
                        ) : (
                            <p className="mt-1 text-[12px] text-muted">
                                Try another filter to see more threads.
                            </p>
                        )}
                    </div>
                ) : filter === "review" && data.review ? (
                    <Fragment key="review">
                        <ThreadGroup
                            groupKey="back-to-you"
                            testId="review-group-back-to-you"
                            title="Back to you"
                            count={data.review.backToYou.length}
                            rows={data.review.backToYou}
                            activeThreadId={activeThreadId}
                        />
                        <ThreadGroup
                            groupKey="not-reviewed"
                            testId="review-group-not-reviewed"
                            title="Not reviewed"
                            count={data.review.notReviewed.length}
                            rows={data.review.notReviewed}
                            activeThreadId={activeThreadId}
                        />
                        <ThreadGroup
                            groupKey="with-yak"
                            testId="review-group-with-yak"
                            title="With Yak"
                            rows={data.review.withYak}
                            activeThreadId={activeThreadId}
                        />
                        <ThreadGroup
                            groupKey="approved"
                            testId="review-group-approved"
                            title="Approved by you"
                            rows={data.review.approved}
                            activeThreadId={activeThreadId}
                            collapsible
                            defaultCollapsed
                        />
                    </Fragment>
                ) : (
                    <Fragment key="threads">
                        <ThreadGroup
                            groupKey="starred"
                            title="Starred"
                            rows={data.starred}
                            activeThreadId={activeThreadId}
                        />
                        <ThreadGroup
                            groupKey="needs-you"
                            title="Needs you"
                            count={data.needsYou.length}
                            rows={data.needsYou}
                            activeThreadId={activeThreadId}
                        />
                        <ThreadGroup
                            groupKey="today"
                            title="Today"
                            rows={rowsOf("today")}
                            activeThreadId={activeThreadId}
                        />
                        <ThreadGroup
                            groupKey="this-week"
                            title="This week"
                            rows={rowsOf("this_week")}
                            activeThreadId={activeThreadId}
                        />
                        <ThreadGroup
                            groupKey="older"
                            title="Older"
                            rows={rowsOf("older")}
                            activeThreadId={activeThreadId}
                        />
                    </Fragment>
                )}
            </div>
        </div>
    );
}
