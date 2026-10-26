export const clientLabels = {
    device: {
        desktop: ["Desktop", "i-tabler-device-desktop"],
        mobile: ["Mobile", "i-tabler-device-mobile"],
        tablet: ["Tablet", "i-tabler-device-tablet"],
        unknown: ["Unknown", "i-tabler-device-unknown"],
    },
    browser: {
        chrome: ["Chrome family", "i-tabler-brand-chrome"],
        chromium: ["Chromium", "i-tabler-brand-chrome"],
        firefox: ["Firefox", "i-tabler-brand-firefox"],
        safari: ["Safari", "i-tabler-brand-safari"],
        edge: ["Edge", "i-tabler-brand-edge"],
        opera: ["Opera", "i-tabler-brand-opera"],
        samsung: ["Samsung Internet", "i-tabler-world"],
        ie: ["Internet Explorer", "i-tabler-world"],
        unknown: ["Unknown", "i-tabler-help"],
    },
    device_brand: {
        apple: ["Apple", "i-tabler-brand-apple"],
        samsung: ["Samsung", "i-tabler-device-mobile"],
        google: ["Google", "i-tabler-brand-google"],
        xiaomi: ["Xiaomi", "i-tabler-device-mobile"],
        huawei: ["Huawei", "i-tabler-device-mobile"],
        honor: ["Honor", "i-tabler-device-mobile"],
        motorola: ["Motorola", "i-tabler-device-mobile"],
        lenovo: ["Lenovo", "i-tabler-device-desktop"],
        asus: ["Asus", "i-tabler-device-desktop"],
        unknown: ["Unknown", "i-tabler-help"],
    },
};
export function describeClient(type, value) {
    const [label, icon] = clientLabels[type]?.[value] || [
        value,
        "i-tabler-help",
    ];
    return { label, icon };
}
