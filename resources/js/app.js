import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import ui from "@nuxt/ui/vue-plugin";
createInertiaApp({
    title: (title) => (title ? `${title} · Perysee` : "Perysee"),
    resolve: () => import("./Pages/Workspace.vue"),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ui)
            .mount(el);
    },
    progress: { color: "#5B21FF", showSpinner: false },
});
