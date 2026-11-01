import { queryUrl, reportFilters } from "./interface-query.mjs";
import { currentRange } from "./period-shift.mjs";
import { filterLogo } from "./client-labels.mjs";
const periodPresets = [
    ["today", "Today", ["aujourd'hui", "day", "jour"]],
    ["7d", "Last 7 days", ["week", "semaine", "7 jours"]],
    ["30d", "Last 30 days", ["month", "mois", "30 jours"]],
];
const aiProviders = [
    ["chatgpt", "ChatGPT", ["openai", "gpt"]],
    ["claude", "Claude", ["anthropic"]],
    ["perplexity", "Perplexity", []],
    ["gemini", "Gemini", ["google", "bard"]],
    ["copilot", "Copilot", ["microsoft", "bing"]],
    ["grok", "Grok", ["xai", "x.ai"]],
];
const browsers = [
    ["chrome", "Chrome", ["google"]],
    ["firefox", "Firefox", ["mozilla"]],
    ["safari", "Safari", ["apple", "webkit"]],
    ["edge", "Edge", ["microsoft"]],
    ["opera", "Opera", []],
];
export function reportItems({ url, currentUrl, period, siteLinks, overview, brands }) {
    if (!siteLinks || !period) return { period: [], filters: [] };
    const query = reportFilters(url);
    const range = currentRange(url, period);
    const comparing = query.compare === "previous";
    const logo = (dimension, value) => {
        const file = filterLogo(dimension, value);
        return file ? { src: `${brands}/color/${file}.svg`, alt: "" } : undefined;
    };
    const dates = { ...range, ...(comparing ? { compare: "previous" } : {}) };
    return {
        period: [
            ...periodPresets.map(([key, label, keywords]) => ({
                label,
                icon: "i-tabler-calendar",
                ...(range.period === key ? { active: true, suffix: "Current" } : {}),
                keywords: ["period", "periode", "date", "range", ...keywords],
                to: queryUrl(currentUrl, { ...query, period: key }),
            })),
            ...(overview
                ? [
                      {
                          label: comparing ? "Stop comparing with the previous period" : "Compare with the previous period",
                          icon: "i-tabler-arrows-diff",
                          ...(comparing ? { active: true, suffix: "Current" } : {}),
                          keywords: ["compare", "comparer", "previous", "precedente", "variation"],
                          to: queryUrl(currentUrl, { ...query, ...range, compare: comparing ? null : "previous" }),
                      },
                  ]
                : []),
        ],
        filters: [
            ...aiProviders.map(([provider, label, keywords]) => ({
                label: `Traffic from ${label}`,
                avatar: logo("provider", provider),
                keywords: ["ai", "ia", "source", "llm", provider, ...keywords],
                to: queryUrl(siteLinks.sources, { ...dates, source_group: "ai", provider }),
            })),
            ...browsers.map(([browser, label, keywords]) => ({
                label: `Visitors using ${label}`,
                avatar: logo("browser", browser),
                keywords: ["browser", "navigateur", "devices", browser, ...keywords],
                to: queryUrl(siteLinks.devices, { ...dates, dimension: "browser", filters: { browser: [browser] } }),
            })),
        ],
    };
}
