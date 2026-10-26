export function zoomMap(chart, factor) {
    const current = chart.getOption().series[0].zoom || 1;
    const next = Math.max(1, Math.min(8, current * factor));
    chart.dispatchAction({
        type: "geoRoam",
        seriesIndex: 0,
        zoom: next / current,
        originX: chart.getWidth() / 2,
        originY: chart.getHeight() / 2,
    });
    return chart.getOption().series[0].zoom;
}
