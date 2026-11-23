/** Mirrors App\Http\Resources\LimitsData. */
export type LimitSetting = {
    id: string;
    label: string;
    help: string | null;
    unit: string | null;
    type: 'int' | 'decimal' | 'bool';
    min: number | null;
    max: number | null;
    default: number | boolean;
    value: number | boolean;
    isOverridden: boolean;
};

export type LimitGroup = {
    id: string;
    title: string;
    description: string | null;
    settings: LimitSetting[];
};

/** Mirrors the Suggestion shape in App\Services\Settings\CapacitySuggestion. */
export type CapacitySuggestion = {
    state: 'up' | 'down' | 'fits' | 'wait';
    cap: number;
    suggested: number | null;
    samplesAtLimit: number;
    totalSamples: number;
    memoryPeak: number;
    cpuPeak: number;
    memoryTotalGb: number;
    memoryUsedGbAtPeak: number;
    cores: number;
    evictions: number;
    highestAwake: number;
    from: string;
    to: string;
    series: { at: string; awake: number; memory: number }[];
};
