import { markRaw, shallowReactive } from "vue";

const SCREEN_PREFIX = "module:";
const MODULE_ID_PATTERN = /^[a-z0-9][a-z0-9-]*$/;
const SLOT_PATTERN = /^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/;

/**
 * Host shared with compiled modules. A module is an IIFE bundle that reaches Vue and Inertia
 * through the global `Perysee` object and registers its screens with `Perysee.registerScreen`.
 */
export function createModuleHost({ document, globals, libraries = {} }) {
    const screens = shallowReactive({});
    const slots = shallowReactive({});
    const settingsPages = shallowReactive({});
    const requests = new Map();

    function registerScreen(name, component) {
        if (!name.startsWith(SCREEN_PREFIX) || name.length === SCREEN_PREFIX.length) {
            throw new Error(`Module screens must be named "${SCREEN_PREFIX}<name>", received "${name}".`);
        }
        if (name in screens) {
            throw new Error(`Screen "${name}" is already registered.`);
        }
        screens[name] = markRaw(component);
    }

    /**
     * Adds a component to a named place of an existing screen, such as "settings.sections".
     * Components are listed by `order`, then by registration.
     */
    function registerSlot(name, component, { order = 0, module } = {}) {
        if (!SLOT_PATTERN.test(name)) {
            throw new Error(`Invalid slot name "${name}": use lowercase dotted names such as "settings.sections".`);
        }
        const entries = [...(slots[name] ?? []), { component: markRaw(component), order, module }];
        slots[name] = entries
            .map((entry, index) => ({ entry, index }))
            .sort((a, b) => a.entry.order - b.entry.order || a.index - b.index)
            .map(({ entry }) => entry);
    }

    /**
     * The components of a slot that are visible on the current page: those of modules active here,
     * and those that belong to no module.
     */
    function visibleSlot(name, activeModules) {
        return (slots[name] ?? [])
            .filter((entry) => entry.module === undefined || activeModules.includes(entry.module))
            .map((entry) => entry.component);
    }

    /**
     * The component shown on a module's settings page, under Settings > Modules.
     */
    function registerSettings(moduleId, component) {
        if (!MODULE_ID_PATTERN.test(moduleId)) {
            throw new Error(`Invalid module id "${moduleId}": use lowercase letters, digits and dashes.`);
        }
        if (moduleId in settingsPages) {
            throw new Error(`Settings for module "${moduleId}" are already registered.`);
        }
        settingsPages[moduleId] = markRaw(component);
    }

    function attach(url, tag) {
        if (!requests.has(url)) {
            const promise = new Promise((resolve, reject) => {
                const element = document.createElement(tag);
                if (tag === "link") {
                    element.rel = "stylesheet";
                    element.href = url;
                } else {
                    element.src = url;
                    element.async = true;
                }
                element.onload = resolve;
                element.onerror = reject;
                document.head.appendChild(element);
            });
            // A failed file is forgotten so the next navigation can retry it.
            promise.catch(() => requests.delete(url));
            requests.set(url, promise);
        }

        return requests.get(url);
    }

    /**
     * Attaches the styles then scripts of the enabled modules, once each.
     * Returns the URLs that failed; a broken module never blocks the others.
     */
    async function load({ scripts = [], styles = [] }) {
        const files = [...styles.map((url) => [url, "link"]), ...scripts.map((url) => [url, "script"])];
        const results = await Promise.allSettled(files.map(([url, tag]) => attach(url, tag)));

        return files.filter((_, index) => results[index].status === "rejected").map(([url]) => url);
    }

    globals.Perysee = { registerScreen, registerSlot, registerSettings, ...libraries };

    return { screens, slots, settingsPages, registerScreen, registerSlot, registerSettings, visibleSlot, load };
}
