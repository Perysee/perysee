import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";
import ui from "@nuxt/ui/vite";
import theme from "./resources/js/brand-theme.json" with { type: "json" };
export default defineConfig({
    plugins: [
        laravel({
            input: [
                "resources/css/app.css",
                "resources/js/app.js",
                "resources/css/legacy.css",
            ],
            refresh: true,
        }),
        vue(),
        ui({
            router: "inertia",
            colorMode: false,
            dts: false,
            ui: {
                colors: theme.colors,
                ...theme.components,
                card: {
                    ...theme.components.card,
                    defaultVariants: {
                        variant: theme.style.defaults.variants.panels,
                    },
                },
                icons: {
                    menu: "i-tabler-menu-2",
                    close: "i-tabler-x",
                    chevronDown: "i-tabler-chevron-down",
                    chevronUp: "i-tabler-chevron-up",
                    chevronLeft: "i-tabler-chevron-left",
                    chevronRight: "i-tabler-chevron-right",
                    chevronDoubleLeft: "i-tabler-chevrons-left",
                    chevronDoubleRight: "i-tabler-chevrons-right",
                    search: "i-tabler-search",
                    loading: "i-tabler-loader-2",
                    check: "i-tabler-check",
                    minus: "i-tabler-minus",
                    plus: "i-tabler-plus",
                    error: "i-tabler-alert-circle",
                    warning: "i-tabler-alert-triangle",
                    info: "i-tabler-info-circle",
                    success: "i-tabler-circle-check",
                    external: "i-tabler-external-link",
                    ellipsis: "i-tabler-dots",
                },
            },
            icon: { clientBundle: { scan: true } },
        }),
    ],
    server: { watch: { ignored: ["**/storage/framework/views/**"] } },
});
