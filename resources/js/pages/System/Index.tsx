import { Head } from "@inertiajs/react";
import { PageHeader, cn } from "@geocodio/console-ui";
import {
    TelemetryPanel,
    TelemetryToolbar,
    TelemetryUiProvider,
    usePanel,
} from "@cboxdk/telemetry-ui";
import type { PanelPayload } from "@cboxdk/telemetry-ui";
import "@cboxdk/telemetry-ui/components.css";
import "../../../css/telemetry-ui.css";
import type { ReactNode } from "react";
import { AppLayout } from "@/layouts/AppLayout";
import { formatPct } from "@/lib/format";
import { useMediaQuery } from "@/lib/useMediaQuery";
import type { PageProps } from "@/types/shared";
import type { SystemPageProps } from "@/types/system";

/** A resource counts as running low from this share used. */
const WARNING_PERCENT = 80;

const GIGABYTE = 1024 ** 3;

type Series = { name: string; data: [number, number | null][] };

function seriesOf(payload: PanelPayload | undefined): Series[] {
    return payload && payload.kind === "chart" ? payload.series : [];
}

function valuesOf(series: Series | undefined): number[] {
    return (series?.data ?? [])
        .map(([, value]) => value)
        .filter(
            (value): value is number =>
                value !== null && Number.isFinite(value),
        );
}

function latestOf(series: Series | undefined): number | null {
    const values = valuesOf(series);

    return values.length > 0 ? values[values.length - 1] : null;
}

function named(series: Series[], name: string): Series | undefined {
    return series.find((candidate) => candidate.name.toLowerCase() === name);
}

function formatGigabytes(bytes: number | null): string {
    return bytes === null ? "-" : `${(bytes / GIGABYTE).toFixed(1)} GB`;
}

function Sparkline({ values }: { values: number[] }) {
    if (values.length < 2) {
        return <div className="h-[30px]" />;
    }

    const width = 200;
    const height = 30;
    const top = Math.max(...values) * 1.1 || 1;
    const points = values.map((value, index) => [
        (index / (values.length - 1)) * width,
        height - (value / top) * (height - 3) - 1,
    ]);
    const line = points
        .map(
            ([x, y], index) =>
                `${index ? "L" : "M"}${x.toFixed(1)} ${y.toFixed(1)}`,
        )
        .join("");
    const [endX, endY] = points[points.length - 1];

    return (
        <svg
            className="block h-[30px] w-full text-accent"
            viewBox={`0 0 ${width} ${height}`}
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <path
                d={`${line}L${width} ${height}L0 ${height}Z`}
                fill="currentColor"
                fillOpacity={0.08}
            />
            <path
                d={line}
                fill="none"
                stroke="currentColor"
                strokeWidth={1.4}
                vectorEffect="non-scaling-stroke"
            />
            <circle cx={endX} cy={endY} r={2.2} fill="currentColor" />
        </svg>
    );
}

type ResourceCardProps = {
    label: string;
    value: string;
    unit?: string;
    detail: string;
    /** Share used, for the bar and the warning tint; null when the resource has no ceiling. */
    percent: number | null;
    history: number[];
};

function ResourceCard({
    label,
    value,
    unit,
    detail,
    percent,
    history,
}: ResourceCardProps) {
    const hasData = value !== "-";
    const isLow = percent !== null && percent >= WARNING_PERCENT;

    return (
        <div
            className={cn(
                "flex min-w-0 flex-col gap-1 rounded-card border border-hair bg-panel px-3.5 pt-3 pb-2.5 shadow-card",
                isLow && "border-warn/40 bg-warn-soft",
            )}
            data-testid="system-resource"
        >
            <span className="flex items-center gap-1.5 text-[12px] text-muted">
                <span
                    className={cn(
                        "size-[7px] shrink-0 rounded-full",
                        !hasData ? "bg-idle" : isLow ? "bg-warn" : "bg-ok",
                    )}
                />
                {label}
            </span>
            {hasData ? (
                <>
                    <span className="text-[22px] font-medium tracking-tight tabular-nums">
                        {value}
                        {unit && (
                            <small className="ml-1 text-[12.5px] font-normal text-faint">
                                {unit}
                            </small>
                        )}
                    </span>
                    {percent !== null && (
                        <div className="mt-1 h-1 overflow-hidden rounded-full bg-panel-2">
                            <span
                                className={cn(
                                    "block h-full rounded-full",
                                    isLow ? "bg-warn" : "bg-accent",
                                )}
                                style={{
                                    width: `${Math.min(100, percent).toFixed(0)}%`,
                                }}
                            />
                        </div>
                    )}
                    <span className="text-[11.5px] text-faint tabular-nums">
                        {detail}
                    </span>
                    <Sparkline values={history} />
                </>
            ) : (
                <span className="py-3 text-[12.5px] text-faint">No data</span>
            )}
        </div>
    );
}

function RightNow() {
    const cpu = seriesOf(usePanel("yak-cpu-utilization").data);
    const memory = seriesOf(usePanel("system-memory").data);
    const memoryRatio = latestOf(
        seriesOf(usePanel("yak-memory-utilization").data)[0],
    );
    const load = seriesOf(usePanel("system-cpu").data);
    const diskUsedSeries = seriesOf(usePanel("yak-app-disk-used").data)[0];
    const diskTotal = latestOf(
        seriesOf(usePanel("yak-app-disk-total").data)[0],
    );
    const poolUsed = seriesOf(usePanel("yak-sandbox-pool-used").data)[0];
    const poolTotal = latestOf(
        seriesOf(usePanel("yak-sandbox-pool-total").data)[0],
    );

    const cpuRatio = latestOf(cpu[0]);
    const cpuPercent = cpuRatio === null ? null : cpuRatio * 100;

    // The memory states overlap (free can include cache), so the total comes
    // from the used share rather than from adding the states up.
    const memoryUsed = latestOf(named(memory, "used"));
    const memoryPercent = memoryRatio === null ? null : memoryRatio * 100;
    const memoryTotal =
        memoryUsed === null || !memoryRatio ? null : memoryUsed / memoryRatio;

    const loadOne = latestOf(named(load, "1m"));
    const loadFive = latestOf(named(load, "5m"));
    const loadFifteen = latestOf(named(load, "15m"));

    const diskUsed = latestOf(diskUsedSeries);
    const diskFree =
        diskUsed === null || diskTotal === null ? null : diskTotal - diskUsed;

    const poolUsedBytes = latestOf(poolUsed);
    const poolPercent =
        poolUsedBytes === null || !poolTotal
            ? null
            : (poolUsedBytes / poolTotal) * 100;

    return (
        <div className="grid grid-cols-1 gap-3 min-[481px]:grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
            <ResourceCard
                label="CPU"
                value={cpuPercent === null ? "-" : cpuPercent.toFixed(0)}
                unit="%"
                detail="All cores"
                percent={cpuPercent}
                history={valuesOf(cpu[0]).map((ratio) => ratio * 100)}
            />
            <ResourceCard
                label="Memory"
                value={
                    memoryUsed === null
                        ? "-"
                        : (memoryUsed / GIGABYTE).toFixed(1)
                }
                unit={`/ ${memoryTotal === null ? "-" : (memoryTotal / GIGABYTE).toFixed(0)} GB`}
                detail={`${formatPct(memoryPercent)} used`}
                percent={memoryPercent}
                history={valuesOf(named(memory, "used"))}
            />
            <ResourceCard
                label="Load"
                value={loadOne === null ? "-" : loadOne.toFixed(2)}
                unit="1 min"
                detail={`5m ${loadFive?.toFixed(2) ?? "-"} · 15m ${loadFifteen?.toFixed(2) ?? "-"}`}
                percent={null}
                history={valuesOf(named(load, "1m"))}
            />
            <ResourceCard
                label="App disk"
                value={
                    diskUsed === null ? "-" : (diskUsed / GIGABYTE).toFixed(0)
                }
                unit={`/ ${diskTotal === null ? "-" : (diskTotal / GIGABYTE).toFixed(0)} GB`}
                detail={`${formatGigabytes(diskFree)} free`}
                percent={
                    diskUsed === null || !diskTotal
                        ? null
                        : (diskUsed / diskTotal) * 100
                }
                history={valuesOf(diskUsedSeries)}
            />
            <ResourceCard
                label="Sandbox pool"
                value={
                    poolUsedBytes === null
                        ? "-"
                        : (poolUsedBytes / GIGABYTE).toFixed(0)
                }
                unit={`/ ${poolTotal === null ? "-" : (poolTotal / GIGABYTE).toFixed(0)} GB`}
                detail="incus-pool"
                percent={poolPercent}
                history={valuesOf(poolUsed)}
            />
        </div>
    );
}

function Section({
    title,
    note,
    testId,
    children,
}: {
    title: string;
    note: string;
    testId: string;
    children: ReactNode;
}) {
    return (
        <section data-testid={testId}>
            <div className="mb-2.5 flex flex-wrap items-baseline gap-x-2.5">
                <h2 className="text-[13.5px] font-semibold">{title}</h2>
                <p className="text-[12px] text-faint">{note}</p>
            </div>
            {children}
        </section>
    );
}

export default function Index({ telemetryBase }: PageProps<SystemPageProps>) {
    const prefersDark = useMediaQuery("(prefers-color-scheme: dark)");

    return (
        <>
            <Head title="System" />
            <TelemetryUiProvider
                config={{ base: telemetryBase }}
                className={prefersDark ? "dark" : undefined}
            >
                <PageHeader crumbs={["System"]}>
                    <div className="ml-4 min-w-0">
                        <TelemetryToolbar />
                    </div>
                </PageHeader>

                <div className="min-h-0 flex-1 overflow-auto">
                    <div className="mx-auto flex max-w-[1200px] flex-col gap-7 px-4 py-6 sm:px-8">
                        <Section
                            title="Right now"
                            note={`Current values. A warning tint appears at ${WARNING_PERCENT}% used.`}
                            testId="system-now"
                        >
                            <RightNow />
                        </Section>

                        <Section
                            title="History"
                            note="For the time window above"
                            testId="system-history"
                        >
                            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                <TelemetryPanel id="yak-cpu-utilization" />
                                <TelemetryPanel id="system-memory" />
                                <TelemetryPanel id="system-cpu" />
                                <TelemetryPanel id="yak-app-disk-used" />
                                <div className="lg:col-span-2">
                                    <TelemetryPanel id="yak-sandbox-pool-used" />
                                </div>
                            </div>
                        </Section>

                        <Section
                            title="Queues"
                            note="Redis queue, 11 workers under supervisord"
                            testId="system-queues"
                        >
                            <div className="flex flex-col gap-3">
                                <TelemetryPanel id="queues-table" />
                                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                    <TelemetryPanel id="queue-backlog" />
                                    <TelemetryPanel id="queue-oldest-job" />
                                </div>
                            </div>
                        </Section>

                        <p className="text-[12px] text-faint">
                            Host values come from inside the Yak container:
                            load, memory and CPU are host-wide. The sandbox pool
                            is read from Incus, because a normal disk check
                            cannot see how full ZFS is.
                        </p>
                    </div>
                </div>
            </TelemetryUiProvider>
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
