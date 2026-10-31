export const clientLabels = {
    device: {
        desktop: ["Desktop", "i-tabler-device-desktop"],
        mobile: ["Mobile", "i-tabler-device-mobile"],
        tablet: ["Tablet", "i-tabler-device-tablet"],
        unknown: ["Unknown", "i-tabler-device-unknown"],
    },
    browser: {
        chrome: ["Chrome family", "i-tabler-brand-chrome", "chrome"],
        chromium: ["Chromium", "i-tabler-brand-chrome"],
        firefox: ["Firefox", "i-tabler-brand-firefox", "firefox"],
        safari: ["Safari", "i-tabler-brand-safari", "safari"],
        edge: ["Edge", "i-tabler-brand-edge", "edge"],
        opera: ["Opera", "i-tabler-brand-opera", "opera"],
        samsung: ["Samsung Internet", "i-tabler-world"],
        ie: ["Internet Explorer", "i-tabler-world", "ie"],
        unknown: ["Unknown", "i-tabler-help"],
    },
    device_brand: {
        apple: ["Apple", "i-tabler-brand-apple", "apple"],
        samsung: ["Samsung", "i-tabler-device-mobile", "samsung"],
        google: ["Google", "i-tabler-brand-google", "google"],
        xiaomi: ["Xiaomi", "i-tabler-device-mobile", "xiaomi"],
        huawei: ["Huawei", "i-tabler-device-mobile", "huawei"],
        honor: ["Honor", "i-tabler-device-mobile", "honor"],
        motorola: ["Motorola", "i-tabler-device-mobile", "motorola"],
        lenovo: ["Lenovo", "i-tabler-device-desktop", "lenovo"],
        asus: ["Asus", "i-tabler-device-desktop", "asus"],
        unknown: ["Unknown", "i-tabler-help"],
    },
};
export function describeClient(type, value) {
    const [label, icon, logo = null] = clientLabels[type]?.[value] || [
        value,
        "i-tabler-help",
    ];
    return { label, icon, logo };
}
const aiSources = {
    "chatgpt.com": "chatgpt",
    "claude.ai": "claude",
    "perplexity.ai": "perplexity",
    "gemini.google.com": "gemini",
    "copilot.microsoft.com": "copilot",
    "grok.com": "grok",
};
export function filterLogo(dimension, value) {
    if (dimension === "provider") return Object.values(aiSources).includes(value) ? value : null;
    if (dimension === "source") return aiSources[value] ?? null;
    if (dimension === "browser" || dimension === "device_brand")
        return describeClient(dimension, value).logo;
    return null;
}
