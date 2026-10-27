export function mapOption(geo, rows, theme, state = {}) {
    const names = new Map(
        geo.features.map((feature) => [
            feature.properties.name,
            feature.properties.label,
        ]),
    );
    const values = rows.filter((row) => /^[A-Z]{2}$/.test(row.value));
    return {
        animation: !theme.reduced,
        textStyle: { fontFamily: theme.font },
        aria: {
            enabled: true,
            description:
                "Measured country pageviews. Exact values are in the country table.",
        },
        tooltip: {
            trigger: "item",
            renderMode: "richText",
            confine: true,
            backgroundColor: theme.background,
            borderColor: theme.border,
            textStyle: { color: theme.text },
            formatter: (entry) =>
                `${names.get(entry.name) || entry.name}\n${Number.isFinite(entry.value) ? entry.value + " pageviews" : "No measured data"}`,
        },
        visualMap: {
            min: 0,
            max: Math.max(1, ...values.map((row) => row.count)),
            left: 16,
            bottom: 8,
            inRange: { color: [theme.subtle, theme.primary] },
            text: ["More", "Less"],
            textStyle: { color: theme.text },
        },
        series: [
            {
                type: "map",
                map: "perysee-world",
                roam: state.wheel ? true : "move",
                zoom: state.zoom || 1,
                center: state.center,
                scaleLimit: { min: 1, max: 8 },
                selectedMode: Array.isArray(state.selected) ? "multiple" : "single",
                itemStyle: {
                    areaColor: theme.muted,
                    borderColor: theme.background,
                    borderWidth: 0.6,
                },
                emphasis: {
                    label: { show: false },
                    itemStyle: { areaColor: theme.primary },
                },
                select: {
                    label: { show: false },
                    itemStyle: { areaColor: theme.primary },
                },
                data: values.map((row) => ({
                    name: row.value,
                    selected: Array.isArray(state.selected) ? state.selected.includes(row.value) : row.value === state.selected,
                    value: row.count,
                })),
            },
        ],
    };
}
