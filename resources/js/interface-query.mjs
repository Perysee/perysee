export function queryUrl(base, values = {}) {
    const url = new URL(base);
    url.search = "";
    for (const [key, value] of Object.entries(values))
        if (value !== null && value !== undefined && value !== "")
            url.searchParams.set(key, String(value));
    return url.href;
}
export function reportFilters(url) {
    const params = new URL(url, "http://perysee.local").searchParams;
    return Object.fromEntries(
        ["value", "source_group", "provider", "dimension"]
            .filter((key) => params.has(key))
            .map((key) => [key, params.get(key)]),
    );
}
