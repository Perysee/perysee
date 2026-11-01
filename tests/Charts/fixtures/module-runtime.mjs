// The module runtime without the browser: the real host, with a plain object in place of `window`.
import * as Vue from "vue";
import * as Inertia from "@inertiajs/vue3";
import { createModuleHost } from "../../../resources/js/module-host.mjs";

export const runtimeGlobals = {};
export const moduleHost = createModuleHost({ document: {}, globals: runtimeGlobals, libraries: { Vue, Inertia } });
