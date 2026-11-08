import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import ui from "@nuxt/ui/vue-plugin";
import { brandState, pageTitle } from "./branding.mjs";
import { registerModuleComponents } from "./module-components.js";
createInertiaApp({
    title: (title) => pageTitle(title, brandState.name),
    // Public share pages are standalone; every other screen goes through the workspace shell.
    resolve: (name) =>
        name.startsWith("Share/")
            ? import.meta.glob("./Pages/Share/*.vue")[`./Pages/${name}.vue`]()
            : import("./Pages/Workspace.vue"),
    setup({ el, App, props, plugin }) {
        brandState.name = props.initialPage?.props?.branding?.name ?? brandState.name;
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ui);
        registerModuleComponents(app);
        app.mount(el);
    },
    progress: { color: "#0066CC", showSpinner: false },
});
