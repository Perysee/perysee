export function variation(current, previous) {
    if (current == null || previous == null) return null;
    if (previous === 0) return current === 0 ? { percent: 0, direction: "flat" } : null;
    const percent = Math.round(((current - previous) / previous) * 1000) / 10;
    return {
        percent,
        direction: percent > 0 ? "up" : percent < 0 ? "down" : "flat",
    };
}
export function describeVariation(change) {
    if (!change) return { text: "—", label: "No comparison available for the previous period" };
    const amount = `${Math.abs(change.percent)} %`;
    if (change.direction === "flat")
        return { text: "0 %", label: "Unchanged compared with the previous period" };
    return change.direction === "up"
        ? { text: `+${amount}`, label: `Up ${amount} compared with the previous period` }
        : { text: `−${amount}`, label: `Down ${amount} compared with the previous period` };
}
// Neutral wording for the reason codes of Reports::comparison(). The server decides; this only renders.
export const reasonTexts = {
    current_period: "The current period is not over yet",
    previous_zero: "No activity in the previous period",
    unavailable: "Not measured in one of the periods",
    partial_coverage: "Incomplete data in one of the periods",
    rebuilding: "Statistics are being rebuilt",
    not_applicable: "Not applicable with this filter",
};
const number = (value) => new Intl.NumberFormat("en").format(value);
const signed = (value) => (value > 0 ? `+${number(value)}` : value < 0 ? `−${number(Math.abs(value))}` : "0");
export function describeComparison(current, previous, reliability) {
    const known = current != null && previous != null;
    const delta = known ? current - previous : null;
    const change = reliability?.percent_allowed && known ? variation(current, previous) : null;
    const described = change ? describeVariation(change) : null;
    return {
        percent: described?.text ?? null,
        label: described?.label ?? null,
        direction: change?.direction ?? "none",
        delta,
        deltaText: delta === null ? null : signed(delta),
        previousText: previous == null ? "—" : number(previous),
        reason: change ? null : (reliability?.reason ?? null),
        reasonText: change ? null : (reasonTexts[reliability?.reason] ?? null),
    };
}
// Previous points by the slot of the current period they line up with (server rank; the index for plain arrays).
export function alignByRank(comparison) {
    const byRank = new Map();
    (comparison ?? []).forEach((point, index) => {
        const rank = point.rank === undefined ? index : point.rank;
        if (rank !== null && !byRank.has(rank)) byRank.set(rank, point);
    });
    return byRank;
}
export const previousLabel = (point) =>
    point.day && point.day !== point.label ? `${point.day} ${point.label}` : point.label;
// Rows of the accessible chart table: each current row gets the aligned previous values.
export function comparisonRows(series, comparison, keys) {
    if (!comparison) return series;
    const byRank = alignByRank(comparison);
    return series.map((point, index) => {
        const previous = byRank.get(index);
        return {
            ...point,
            previous_label: previous ? previousLabel(previous) : null,
            ...Object.fromEntries(keys.map((key) => [`previous_${key}`, previous?.[key] ?? null])),
        };
    });
}
