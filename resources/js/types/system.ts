/** Mirrors the props SystemController passes to `System/Index`. */
export type SystemPageProps = {
    telemetryBase: string;
    /** The package page shown, by the package's own path (`p/system`, `explore/traces`, `traces/<id>`). */
    path: string;
    /** Counts of awake sandboxes per person; null unless the viewer manages Yak accounts. */
    awakeSlots: AwakeSlots | null;
};

export type AwakeSlots = {
    total: number;
    used: number;
    perPersonCap: number;
    people: { name: string; initials: string; awake: number }[];
};
