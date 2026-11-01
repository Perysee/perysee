import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import ui from "@nuxt/ui/vue-plugin";
import { brandState, pageTitle } from "./branding.mjs";
createInertiaApp({
    title: (title) => pageTitle(title, brandState.name),
    resolve: () => import("./Pages/Workspace.vue"),
    setup({ el, App, props, plugin }) {
        brandState.name = props.initialPage?.props?.branding?.name ?? brandState.name;
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ui)
            .mount(el);
    },
    progress: { color: "#5B21FF", showSpinner: false },
});
