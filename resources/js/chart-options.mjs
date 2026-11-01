export const metricLabels = {
    pageviews: "Pageviews",
    sessions: "Sessions started",
    custom_events: "Custom events",
};
export function lineOption(
    series,
    metric,
    {
        compact = false,
        reduced = false,
        primary = "#5516FF",
        text = "#00020C",
        border = "#00020C24",
        background = "#F8F8F8",
        font = "Geist, sans-serif",
        comparison = null,
    } = {},
) {
    const option = {
        animation: !reduced && !compact,
        animationDuration: 300,
        textStyle: { fontFamily: font, color: text },
        aria: { enabled: !compact },
        grid: {
            left: compact ? 0 : 48,
            right: compact ? 0 : 15,
            top: compact ? 4 : 22,
            bottom: compact ? 0 : 38,
        },
        tooltip: {
            show: !compact,
            trigger: "axis",
            renderMode: "richText",
            confine: true,
            backgroundColor: background,
            borderColor: border,
            textStyle: { color: text, fontSize: 12 },
            axisPointer: {
                type: "line",
                lineStyle: { color: border, type: "solid" },
            },
        },
        xAxis: {
            type: "category",
            data: series.map((point) => point.label),
            show: !compact,
            boundaryGap: false,
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: {
                color: text,
                fontSize: 12,
                hideOverlap: true,
                margin: 14,
            },
        },
        yAxis: {
            type: "value",
            show: !compact,
            min: 0,
            minInterval: 1,
            axisLabel: { color: text, fontSize: 12 },
            splitLine: { lineStyle: { color: border, type: "solid" } },
        },
        series: [
            {
                name: metricLabels[metric],
                type: "line",
                data: series.map((point) => point[metric] ?? null),
                connectNulls: false,
                smooth: false,
                showSymbol: series.length === 1,
                symbolSize: 6,
                lineStyle: { color: primary, width: compact ? 2 : 2.5 },
                itemStyle: { color: primary },
                areaStyle: { color: primary, opacity: 0.1 },
                emphasis: { focus: "series" },
            },
        ],
    };
    if (!comparison) return option;
    option.legend = { show: !compact, top: 0, right: 0, textStyle: { color: text, fontSize: 12 } };
    option.tooltip.formatter = (points) => {
        const index = points[0].dataIndex;
        return [
            series[index]?.label,
            ...points.map(({ seriesName, value }, position) =>
                position === 0 || !comparison[index]
                    ? `${seriesName}: ${value ?? "—"}`
                    : `${seriesName} (${comparison[index].label}): ${value ?? "—"}`,
            ),
        ].join("\n");
    };
    option.series.push({
        name: "Previous period",
        type: "line",
        data: series.map((_, index) => comparison[index]?.[metric] ?? null),
        connectNulls: false,
        smooth: false,
        showSymbol: false,
        lineStyle: { color: text, width: 1.5, type: "dashed", opacity: 0.55 },
        itemStyle: { color: text, opacity: 0.55 },
        emphasis: { focus: "series" },
    });
    return option;
}
