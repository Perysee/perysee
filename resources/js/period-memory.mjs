const key = (siteId) => `perysee-period:${siteId}`;
const presets = ["today", "7d", "30d"];
const isDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value ?? "");
const hasPeriod = (params) =>
    ["from", "to", "period"].some((name) => params.has(name));
function read(storage, siteId) {
    try {
        const stored = JSON.parse(storage.getItem(key(siteId)));
        if (presets.includes(stored?.period))
            return { period: stored.period, compare: stored.compare };
        if (isDate(stored?.from) && isDate(stored?.to))
            return { from: stored.from, to: stored.to, compare: stored.compare };
    } catch {
        // Storage can be blocked or hold invalid JSON: behave as if nothing was remembered.
    }
    return null;
}
export function remember(storage, siteId, search) {
    const params = new URLSearchParams(search);
    if (!hasPeriod(params)) return;
    const snapshot = Object.fromEntries(
        ["period", "from", "to", "compare"]
            .filter((name) => params.has(name))
            .map((name) => [name, params.get(name)]),
    );
    try {
        storage.setItem(key(siteId), JSON.stringify(snapshot));
    } catch {
        // Persistence is a convenience only.
    }
}
export function restore(storage, siteId, search) {
    const params = new URLSearchParams(search);
    if (!storage || hasPeriod(params)) return null;
    const stored = read(storage, siteId);
    if (!stored) return null;
    const compare = params.get("compare") ?? stored.compare;
    const restored = new URLSearchParams(
        stored.period
            ? { period: stored.period }
            : { from: stored.from, to: stored.to },
    );
    if (compare === "previous") restored.set("compare", compare);
    return restored.toString();
}
