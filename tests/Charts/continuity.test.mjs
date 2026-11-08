import { test } from "node:test";
import assert from "node:assert/strict";
import { lineOption } from "../../resources/js/chart-options.mjs";
import { chartMarkers, describeContinuity, kindLabels, markerText } from "../../resources/js/continuity.mjs";

const series = [
    { date: "2026-10-18", label: "2026-10-18", pageviews: 4, sessions: 2 },
    { date: "2026-10-19", label: "2026-10-19", pageviews: 6, sessions: 3 },
    { date: "2026-10-20", label: "2026-10-20", pageviews: 8, sessions: 4 },
];
const entry = (at, kind, scope = "all", severity = "breaks") => ({ at, kind, scope, severity });

test("a break becomes one marker on the matching day, with a short text label", () => {
    const markers = chartMarkers(series, [entry("2026-10-19", "consent")], "pageviews");
    assert.deepEqual(markers, [{ x: "2026-10-19", text: "Consent", description: "Consent changed on Oct 19, 2026" }]);
});

test("notes, other scopes and days outside the chart draw nothing", () => {
    const entries = [
        entry("2026-10-19", "timezone", "all", "note"),
        entry("2026-10-19", "tracking_mode", "sessions"),
        entry("2026-10-01", "consent"),
    ];
    assert.deepEqual(chartMarkers(series, entries, "pageviews"), []);
    assert.equal(chartMarkers(series, entries, "sessions").length, 1);
    assert.deepEqual(chartMarkers(series, undefined, "pageviews"), []);
});

test("two breaks on the same day share one marker", () => {
    const [marker] = chartMarkers(series, [entry("2026-10-20", "consent"), entry("2026-10-20", "currency", "events")], "custom_events");
    assert.equal(marker.text, "Consent, Currency");
});

test("hourly points cannot place a day marker", () => {
    const hourly = [{ date: "2026-10-19 10:00:00", label: "12:00 CEST", pageviews: 1 }];
    assert.deepEqual(chartMarkers(hourly, [entry("2026-10-19", "consent")], "pageviews"), []);
});

test("the chart draws a dotted vertical markLine per marker and says so in its text alternative", () => {
    const markers = chartMarkers(series, [entry("2026-10-19", "consent")], "pageviews");
    const option = lineOption(series, "pageviews", { markers });
    const line = option.series[0].markLine;
    assert.equal(line.silent, true);
    assert.equal(line.symbol, "none");
    assert.equal(line.data.length, 1);
    assert.equal(line.data[0].xAxis, "2026-10-19");
    assert.equal(line.data[0].label.formatter, "Consent");
    assert.equal(line.data[0].label.show, true);
    assert.equal(line.lineStyle.type, "dotted", "not coded by colour alone: dotted stroke and a text label");
    assert.match(option.aria.label.description, /Consent changed on Oct 19, 2026/);
    assert.match(option.aria.label.description, /not comparable/i);
});

test("without markers the chart is unchanged", () => {
    const plain = lineOption(series, "pageviews");
    assert.equal(plain.series[0].markLine, undefined);
    assert.equal(plain.aria.label, undefined);
    assert.deepEqual(lineOption(series, "pageviews", { markers: [] }), plain);
});

test("markers and comparison keep both descriptions", () => {
    const markers = chartMarkers(series, [entry("2026-10-19", "consent")], "pageviews");
    const option = lineOption(series, "pageviews", { markers, comparison: [{ label: "x", pageviews: 1 }] });
    assert.match(option.aria.label.description, /previous period/);
    assert.match(option.aria.label.description, /Consent/);
    assert.equal(option.series.length, 2);
    assert.ok(option.series[0].markLine);
    assert.equal(option.series[1].markLine, undefined);
});

test("the report notes list every entry with the site's date and the not-comparable sentence for breaks", () => {
    const rows = describeContinuity([entry("2026-10-17", "consent"), entry("2026-10-18", "timezone", "all", "note"), entry("2026-10-19", "tracking_mode", "sessions")]);
    assert.deepEqual(rows[0], { key: "2026-10-17:consent:all", date: "Oct 17, 2026", label: "Consent", scopeText: "all reports", severity: "breaks", sentence: "Figures are not comparable before this date." });
    assert.equal(rows[1].sentence, "Figures stay comparable across this change.");
    assert.equal(rows[2].scopeText, "session figures");
    assert.equal(rows[2].sentence, "Session figures are not comparable before this date.");
    assert.deepEqual(describeContinuity(null), []);
});

test("every kind the server can write has a label, and an unknown one stays readable", () => {
    for (const kind of ["tracking_mode", "consent", "allowed_domains", "excluded_paths", "currency", "timezone", "retention_change", "retention_purge", "storage_tier", "core_version", "tracker_version", "rebuild"]) {
        assert.ok(kindLabels[kind], kind);
    }
    assert.equal(markerText("something_new"), "something new");
});

test("the echarts component that draws a marker is registered, or the line is silently dropped", async () => {
    const { readFileSync } = await import("node:fs");
    const registered = readFileSync(new URL("../../resources/js/vue-charts.js", import.meta.url), "utf8");
    const option = lineOption(series, "pageviews", { markers: chartMarkers(series, [entry("2026-10-19", "consent")], "pageviews") });
    assert.ok(option.series[0].markLine, "the option carries a markLine");
    assert.match(registered, /\bMarkLineComponent\b/);
});
