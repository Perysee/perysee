const ACCENT_PATTERN = /^#[0-9a-fA-F]{6}$/;
const DEFAULT_NAME = "Perysee";

/** Shared with the Inertia title callback, which runs outside any component. */
export const brandState = { name: DEFAULT_NAME };

/**
 * CSS custom properties that recolor the interface with one accent. The value is validated again here,
 * so nothing but a six digit hex color can reach a stylesheet.
 */
export function accentCss(accent) {
    if (typeof accent !== "string" || !ACCENT_PATTERN.test(accent)) {
        return "";
    }
    const hover = `color-mix(in srgb, ${accent} 85%, #00020C)`;

    return (
        `:root{--ui-primary:${accent};--brand-action:${accent};--brand-action-hover:${hover};--brand-subtle:color-mix(in srgb, ${accent} 8%, #F8F8F8);}` +
        `.dark{--ui-primary:color-mix(in srgb, ${accent} 55%, #FFFFFF);--brand-action:${accent};--brand-action-hover:${hover};--brand-subtle:color-mix(in srgb, ${accent} 18%, #00020C);}`
    );
}

export function pageTitle(title, name = DEFAULT_NAME) {
    return title ? `${title} · ${name}` : name;
}
