import * as Vue from "vue";
import * as Inertia from "@inertiajs/vue3";
import { createModuleHost } from "./module-host.mjs";

export const moduleHost = createModuleHost({
    document,
    globals: window,
    libraries: { Vue, Inertia },
});
