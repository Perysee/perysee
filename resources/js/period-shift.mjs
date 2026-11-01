const DAY = 86_400_000;
const parse = (value) =>
    /^\d{4}-\d{2}-\d{2}$/.test(value ?? "") ? Date.parse(`${value}T00:00:00Z`) : NaN;
const format = (time) => new Date(time).toISOString().slice(0, 10);
export function shiftPeriod(from, to, direction, today) {
    const start = parse(from),
        end = parse(to),
        limit = parse(today);
    if (![start, end, limit].every(Number.isFinite) || !direction) return null;
    const length = end - start + DAY;
    if (direction > 0) {
        if (end >= limit) return null;
        const newEnd = Math.min(end + length, limit);
        return { from: format(newEnd - length + DAY), to: format(newEnd) };
    }
    return { from: format(start - length), to: format(end - length) };
}
const presets = ["today", "7d", "30d"];
export function currentRange(url, period) {
    const preset = new URL(url, "http://perysee.local").searchParams.get("period");
    return presets.includes(preset)
        ? { period: preset }
        : { from: period.from, to: period.to };
}
