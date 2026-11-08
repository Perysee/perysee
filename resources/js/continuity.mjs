// What the report shows of the continuity log: the entries arrive as {at, kind, scope, severity} (the site's local
// day, never a payload) and are only worded here. The server decides what breaks comparability.
export const kindLabels = {
    tracking_mode: "Measurement mode",
    consent: "Consent",
    allowed_domains: "Accepted hosts",
    excluded_paths: "Excluded paths",
    currency: "Currency",
    timezone: "Timezone",
    retention_change: "Retention",
    retention_purge: "Purge",
    storage_tier: "Storage tier",
    core_version: "Update",
    tracker_version: "Tracker",
    rebuild: "Rebuild",
};
const scopeTexts = {
    all: "all reports",
    sessions: "session figures",
    pageviews: "pageview figures",
    events: "event figures",
    goals: "goal figures",
    segments: "segment figures",
};
// The reports (chart metrics) each scope reaches; `all` reaches every one.
const metricScopes = {
    pageviews: ["all", "pageviews"],
    sessions: ["all", "sessions"],
    custom_events: ["all", "events"],
    conversions: ["all", "goals"],
    hits: ["all", "goals"],
};
export const markerText = (kind) => kindLabels[kind] ?? kind.replaceAll("_", " ");
// A plain local day (no clock, no zone shift) in a stable wording.
export const dayText = (at) =>
    new Intl.DateTimeFormat("en", { dateStyle: "medium", timeZone: "UTC" }).format(new Date(`${at}T00:00:00Z`));
// One vertical marker per day on which a break touches the metric; only daily points can carry one.
export function chartMarkers(series, entries, metric) {
    const days = new Set(series.map((point) => point.date).filter((date) => typeof date === "string" && date.length === 10));
    const scopes = metricScopes[metric] ?? ["all"];
    const byDay = new Map();
    for (const entry of entries ?? []) {
        if (entry.severity !== "breaks" || !days.has(entry.at) || !scopes.includes(entry.scope)) continue;
        byDay.set(entry.at, [...(byDay.get(entry.at) ?? []), entry.kind]);
    }
    return [...byDay].map(([x, kinds]) => ({
        x,
        text: kinds.map(markerText).join(", "),
        description: `${kinds.map(markerText).join(", ")} changed on ${dayText(x)}`,
    }));
}
// Rows of the "About these numbers" list.
export function describeContinuity(entries) {
    return (entries ?? []).map((entry) => {
        const breaks = entry.severity === "breaks";
        const everything = entry.scope === "all";
        const subject = everything ? "Figures are" : `${scopeTexts[entry.scope] ?? "Figures"} are`;
        return {
            key: `${entry.at}:${entry.kind}:${entry.scope}`,
            date: dayText(entry.at),
            label: markerText(entry.kind),
            scopeText: scopeTexts[entry.scope] ?? entry.scope,
            severity: entry.severity,
            sentence: breaks
                ? `${everything ? subject : subject[0].toUpperCase() + subject.slice(1)} not comparable before this date.`
                : "Figures stay comparable across this change.",
        };
    });
}
