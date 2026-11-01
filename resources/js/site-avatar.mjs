export const initials = (name) =>
    (String(name ?? "").match(/[\p{L}\p{N}]+/gu) ?? [])
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join("");
// Asks the favicon route for "no content" when a site has no icon, so the avatar falls back to initials.
export const siteAvatar = (src, name) => ({
    src: src ? `${src}${src.includes("?") ? "&" : "?"}initials=1` : undefined,
    alt: name,
    text: initials(name),
});
