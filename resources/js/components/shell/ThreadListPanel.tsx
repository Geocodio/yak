import { usePage } from "@inertiajs/react";
import {
    Button,
    IconButton,
    Skeleton,
    Tooltip,
    cn,
} from "@geocodio/console-ui";
import { CircleHelp, PanelLeftClose, Plus, Search } from "lucide-react";
import { keycapsFor } from "@/commandPalette/shortcuts";
import { isMacPlatform } from "@/commandPalette/platform";
import { ThreadGroup } from "@/components/shell/ThreadGroup";
import { useThreadList } from "@/components/shell/useThreadList";
import { openNewThread, openPalette } from "@/components/Sidebar";
import type { SharedProps } from "@/types/shared";
import type { ThreadListFilter } from "@/types/shell";

const FILTERS: { value: ThreadListFilter; label: string }[] = [
    { value: "mine", label: "Mine" },
    { value: "yak", label: "Started by Yak" },
    { value: "everyone", label: "Everyone" },
];

const FILTER_HELP =
    "Mine: threads you started or took part in, plus unattended threads on repos you follow. Started by Yak: threads that came from a channel, such as Sentry or Slack. Everyone: every thread you can see.";

export function ThreadListPanel({
    onCollapse,
    touch = false,
}: {
    onCollapse?: () => void;
    touch?: boolean;
}) {
    const { props, url } = usePage<SharedProps>();
    const { data, filter, setFilter, isLoading } =
        useThreadList(props.threadList);
    const activeThreadId =
        /^\/threads\/([^/?#]+)/.exec(url.split("?")[0])?.[1] ?? null;
    const hasNoThreadsAtAll =
        data !== null && !data.hasAnyThreads;
    const isEmpty =
        data !== null &&
        data.needsYou.length === 0 &&
        data.threads.length === 0;
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
                        label={`Search ${keycapsFor("openPalette", isMacPlatform()).join(" ")}`}
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
                            className="flex items-center gap-1.5"
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
                                            "rounded-control px-2.5 py-0.5 text-[12px] text-muted",
                                            filter === option.value &&
                                                "bg-panel font-medium text-body shadow-card",
                                        )}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                            <Tooltip label={FILTER_HELP}>
                                <span
                                    className="flex text-faint"
                                    aria-label={FILTER_HELP}
                                >
                                    <CircleHelp size={13} />
                                </span>
                            </Tooltip>
                        </div>
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
                ) : hasNoThreadsAtAll ? null : isEmpty ? (
                    <div
                        className="mt-3 rounded-card border border-dashed border-hair p-4 text-center"
                        data-testid="thread-list-empty"
                    >
                        <div className="text-[13px] font-medium">
                            No threads here
                        </div>
                        <p className="mt-1 text-[12px] text-muted">
                            Try another filter to see more threads.
                        </p>
                    </div>
                ) : (
                    <>
                        <ThreadGroup
                            groupKey="needs-you"
                            title="Needs you"
                            count={data.needsYouCount}
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
                    </>
                )}
            </div>
        </div>
    );
}
