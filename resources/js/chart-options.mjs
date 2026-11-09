import { alignByRank, previousLabel } from "./comparison.mjs";
export const metricLabels = {
    pageviews: "Pageviews",
    sessions: "Sessions started",
    custom_events: "Custom events",
    conversions: "Goal conversions",
    hits: "Goal hits",
};
const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
// The axis shows "Nov 3" for a daily label; the tooltip keeps the full date of the data.
export function shortDate(label) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(label));
    return match ? `${MONTHS[Number(match[2]) - 1]} ${Number(match[3])}` : label;
}

export function lineOption(
    series,
    metric,
    {
        compact = false,
        reduced = false,
        primary = "#0066CC",
        area = primary,
        text = "#1D1D1F",
        border = "#1D1D1F24",
        background = "#FBFBFD",
        font = "Aspekta, sans-serif",
        comparison = null,
        markers = [],
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
                formatter: shortDate,
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
                areaStyle: { color: area, opacity: 0.1 },
                emphasis: { focus: "series" },
            },
        ],
    };
    let markerSentence = "";
    if (markers.length) {
        // A dotted vertical line and a short text, so a break is never told by its colour alone.
        option.series[0].markLine = {
            silent: true,
            symbol: "none",
            animation: false,
            lineStyle: { color: text, width: 1.5, type: "dotted", opacity: 0.8 },
            data: markers.map((marker) => ({
                xAxis: marker.x,
                label: { show: true, formatter: marker.text, position: "insideEndTop", color: text, fontSize: 11 },
            })),
        };
        markerSentence = `Dotted vertical lines mark changes after which figures are not comparable: ${markers.map((marker) => marker.description).join("; ")}.`;
        option.aria.label = { description: `${metricLabels[metric]} over time. ${markerSentence}` };
    }
    if (!comparison) return option;
    // Align by the rank the server gives each previous point (day rank, or local hour slot); plain arrays fall back to their index.
    const byRank = alignByRank(comparison);
    const when = previousLabel;
    option.aria.label = {
        description: `${metricLabels[metric]} over time, with a dashed line with diamond markers for the previous period of the same length.${markerSentence && ` ${markerSentence}`}`,
    };
    option.legend = { show: !compact, top: 0, right: 0, textStyle: { color: text, fontSize: 12 } };
    option.tooltip.formatter = (points) => {
        const index = points[0].dataIndex;
        const previous = byRank.get(index);
        return [
            series[index]?.label,
            ...points.map(({ seriesName, value }, position) =>
                position === 0 || !previous
                    ? `${seriesName}: ${value ?? "—"}`
                    : `${seriesName} (${when(previous)}): ${value ?? "—"}`,
            ),
        ].join("\n");
    };
    option.series.push({
        name: "Previous period",
        type: "line",
        data: series.map((_, index) => byRank.get(index)?.[metric] ?? null),
        connectNulls: false,
        smooth: false,
        showSymbol: !compact,
        symbol: "emptyDiamond",
        symbolSize: 7,
        lineStyle: { color: text, width: 1.5, type: "dashed", opacity: 0.55 },
        itemStyle: { color: text, opacity: 0.7 },
        emphasis: { focus: "series" },
    });
    return option;
}
