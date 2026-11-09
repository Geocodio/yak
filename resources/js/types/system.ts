/** Mirrors the props SystemController passes to `System/Index`. */
export type SystemPageProps = {
    telemetryBase: string;
    /** The package page shown, by the package's own path (`p/system`, `explore/traces`, `traces/<id>`). */
    path: string;
};
