export const sectionGroups = [
    {
        label: "Traffic",
        sections: [
            { key: "overview", label: "Overview", icon: "i-tabler-chart-line", keywords: ["home", "summary", "resume", "accueil", "stats"] },
            { key: "pages", label: "Pages", icon: "i-tabler-file-text", keywords: ["paths", "urls", "content", "pages vues", "contenu"] },
            { key: "sources", label: "Sources", icon: "i-tabler-arrow-up-right", keywords: ["referrers", "traffic", "ai", "ia", "search", "origine", "trafic"] },
            { key: "campaigns", label: "Campaigns", icon: "i-tabler-speakerphone", keywords: ["utm", "marketing", "campagnes", "newsletter"] },
        ],
    },
    {
        label: "Audience",
        sections: [
            { key: "devices", label: "Devices", icon: "i-tabler-device-desktop", keywords: ["browser", "navigateur", "mobile", "desktop", "tablet", "appareils", "os"] },
            { key: "countries", label: "Countries", icon: "i-tabler-world", keywords: ["pays", "country", "map", "carte", "geo", "location"] },
            { key: "sessions", label: "Sessions", icon: "i-tabler-users", keywords: ["visits", "visiteurs", "visitors"] },
        ],
    },
    {
        label: "Behavior",
        sections: [
            { key: "events", label: "Events", icon: "i-tabler-bolt", keywords: ["conversions", "evenements", "custom", "goals"] },
            { key: "funnels", label: "Funnels", icon: "i-tabler-filter", keywords: ["conversion", "entonnoirs", "steps", "etapes"] },
            { key: "goals", label: "Goals", icon: "i-tabler-target", keywords: ["conversions", "objectifs", "targets", "signup", "taux"] },
            { key: "journeys", label: "Journeys", icon: "i-tabler-route", keywords: ["parcours", "paths", "flow", "sankey"] },
        ],
    },
    {
        label: "Live",
        sections: [
            { key: "realtime", label: "Recent activity", icon: "i-tabler-activity", keywords: ["live", "now", "recent", "temps reel", "activity"] },
        ],
    },
];
export const sectionList = sectionGroups.flatMap(({ sections }) => sections);
export const groupedItems = (toItem) =>
    sectionGroups.flatMap(({ label, sections }) => [
        { label, type: "label" },
        ...sections.map(toItem),
    ]);
export const limitSites = (sites, max) => ({
    visible: sites.slice(0, max),
    hidden: Math.max(0, sites.length - max),
});
