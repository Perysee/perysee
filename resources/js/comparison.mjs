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
    if (!change) return { text: "New", label: "No data in the previous period" };
    const amount = `${Math.abs(change.percent)} %`;
    if (change.direction === "flat")
        return { text: "0 %", label: "Unchanged compared with the previous period" };
    return change.direction === "up"
        ? { text: `+${amount}`, label: `Up ${amount} compared with the previous period` }
        : { text: `−${amount}`, label: `Down ${amount} compared with the previous period` };
}
