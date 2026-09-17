import { useMemo } from 'react';
import { EChart, type EChartsOption } from '@/components/analytics/EChart';
import { singleHue, useChartTheme } from '@/components/analytics/chartTheme';
import { asParams, numericValue, tooltipHtml } from '@/components/analytics/tooltip';
import { formatCount } from '@/lib/format';

/**
 * A single-hue horizontal bar list: category names on the left, the count
 * at each bar's end. Height grows with the row count so nothing scrolls.
 */
export function HorizontalBars({ rows, valueLabel }: { rows: { label: string; value: number }[]; valueLabel: string }) {
    const { tokens, palette } = useChartTheme();
    const max = Math.max(1, ...rows.map((row) => row.value));

    const option = useMemo<EChartsOption>(
        () => ({
            grid: { left: 4, right: 48, top: 4, bottom: 4, containLabel: true },
            xAxis: { type: 'value', show: false, max: max * 1.08 },
            yAxis: {
                type: 'category',
                inverse: true,
                data: rows.map((row) => row.label),
                axisLabel: { color: tokens.text2, fontSize: 11, width: 140, overflow: 'truncate' },
            },
            tooltip: {
                trigger: 'item',
                formatter: (raw: unknown) => {
                    const [param] = asParams(raw);

                    return param ? tooltipHtml(String(param.name), [{ color: singleHue(palette), label: valueLabel, value: formatCount(numericValue(param)) }]) : '';
                },
            },
            series: [
                {
                    type: 'bar',
                    data: rows.map((row) => row.value),
                    barMaxWidth: 18,
                    barCategoryGap: '40%',
                    itemStyle: { color: singleHue(palette), borderRadius: [0, 4, 4, 0] },
                    label: {
                        show: true,
                        position: 'right',
                        distance: 6,
                        color: tokens.text2,
                        fontSize: 11,
                        formatter: (param: unknown) => formatCount(numericValue(asParams(param)[0]) ?? 0),
                    },
                },
            ],
        }),
        [rows, max, valueLabel, palette, tokens.text2],
    );

    return <EChart option={option} height={Math.max(120, 28 * rows.length + 8)} />;
}
