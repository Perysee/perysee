// A Vite server in SSR mode that loads the real single file components and the real Nuxt UI components.
// Inertia and the module runtime are replaced by small stand-ins, because there is no browser here.
import { createServer, loadConfigFromFile } from "vite";

export const root = new URL("../../../", import.meta.url).pathname;

export async function createRenderServer() {
    const loaded = await loadConfigFromFile({ command: "serve", mode: "test" }, `${root}vite.config.js`, root);

    return createServer({
        ...loaded.config,
        root,
        configFile: false,
        server: { middlewareMode: true, hmr: false, watch: null },
        appType: "custom",
        logLevel: "silent",
        ssr: { noExternal: [/@nuxt\/ui/, /@inertiajs/] },
        resolve: {
            ...loaded.config.resolve,
            alias: [
                { find: "@inertiajs/vue3", replacement: `${root}tests/Charts/fixtures/inertia.mjs` },
                { find: /^.*\/module-runtime\.js$/, replacement: `${root}tests/Charts/fixtures/module-runtime.mjs` },
                { find: /^.*\/ColorModeButton\.vue$/, replacement: `${root}tests/Charts/fixtures/color-mode-button.mjs` },
            ],
        },
    });
}
