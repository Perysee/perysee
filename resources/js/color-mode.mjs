export function applyColorMode(dark, root, storage) {
    root.classList.toggle("dark", dark);
    try {
        storage?.setItem("perysee-color-mode", dark ? "dark" : "light");
    } catch {
        // Appearance still works when storage is unavailable.
    }
}
