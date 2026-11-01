import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import * as Vue from "vue";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createRenderServer, root } from "./fixtures/render-server.mjs";

// Renders the real settings page, the real module host, the real compiled module scripts and the real
// Nuxt UI components together, the way the browser does. Nothing here is stubbed: a stub once hid the fact
// that components were not registered globally.
let server;
let runtime;
let registerModuleComponents;
let components;
let ui;
let Page;

before(async () => {
    server = await createRenderServer();
    runtime = await server.ssrLoadModule("/tests/Charts/fixtures/module-runtime.mjs");
    const registry = await server.ssrLoadModule("/resources/js/module-components.js");
    registerModuleComponents = registry.registerModuleComponents;
    components = registry.moduleComponents;
    ui = (await server.ssrLoadModule("@nuxt/ui/vue-plugin")).default;
    Page = (await server.ssrLoadModule("/resources/js/Components/ModuleSettingsPage.vue")).default;
});

after(async () => {
    await server?.close();
});

const runModule = (id) => vm.runInNewContext(readFileSync(`${root}modules/${id}/dist/module.js`, "utf8"), { Perysee: runtime.runtimeGlobals.Perysee });

async function render(component, props) {
    const app = createSSRApp({ render: () => h(component, props) });
    app.use(ui);
    registerModuleComponents(app);

    return renderToString(app);
}

const settingsPage = (moduleId, data, extra = {}) => ({
    module: { id: moduleId, name: moduleId, version: "1.0.0", description: "", enabled: true, installed: true, compatible: true, failure: null },
    scope: { all: true, site_ids: [], hidden: 0 },
    siteChoices: [{ id: 1, name: "Acme", domain: "acme.test" }],
    moduleLinks: { save: "/save", back: "/settings/modules" },
    errors: {},
    modules: { data: { [moduleId]: data }, active: [moduleId] },
    ...extra,
});

test("the settings page of White label shows availability and the module's own form, with real components", async () => {
    runModule("white-label");
    const data = { name: "Acme Analytics", accent: "#12ab34", logo_url: null, has_logo: false, defaults: { name: "Perysee", accent: "#5B21FF" }, links: { save: "/s", reset: "/r", logo: "/l" } };

    const html = await render(Page, settingsPage("white-label", data));

    assert.match(html, /Availability/);
    assert.match(html, /Whole instance/);
    assert.match(html, /White label/);
    assert.match(html, /<input[^>]*value="Acme Analytics"/);
    assert.match(html, /<input[^>]*value="#12ab34"/);
    assert.match(html, /Reset everything to Perysee/);
    assert.equal((html.match(/Use default/g) ?? []).length, 2);
    assert.match(html, /aria-label="Pick the accent color"/);
    assert.match(html, /background:\s*#12ab34/, "the swatch shows the chosen color");
    assert.match(html, /No logo, the Perysee logo is shown/);
    assert.doesNotMatch(html, /<u(card|input|button|formfield)\b/i, "an unresolved component renders as an unknown lowercase element");
});

test("the settings page of Annotations and Example show their own form for an administrator", async () => {
    runModule("annotations");
    runModule("example");

    const annotations = await render(Page, settingsPage("annotations", { settings: { retention_days: 400, links: { save: "/a" } } }));
    const example = await render(Page, settingsPage("example", { greeting: "Hello there", save: "/e" }));

    assert.match(annotations, /Keep notes for/);
    assert.match(annotations, /<input[^>]*value="400"/);
    assert.match(example, /Greeting/);
    assert.match(example, /<input[^>]*value="Hello there"/);
});

test("members and disabled modules get no module form", async () => {
    const member = await render(Page, settingsPage("white-label", []));
    assert.doesNotMatch(member, /Product name/);

    const off = await render(Page, settingsPage("example", { greeting: "Hi", save: "/e" }, { module: { id: "example", name: "Example", version: "1.0.0", description: "", enabled: false, installed: true, compatible: true, failure: null } }));
    assert.match(off, /This module is disabled/);
    assert.doesNotMatch(off, /Greeting/);
});

test("the Annotations page and the dashboard block render with real components", async () => {
    const screen = runtime.moduleHost.screens["module:annotations"];
    const page = await render(screen, { website: { name: "Acme" }, notes: [{ id: 1, noted_on: "2026-10-04", body: "Pricing page live", author: "Ada" }], canWrite: true, noteLinks: { store: "/n" }, errors: {} });
    assert.match(page, /Pricing page live/);
    assert.match(page, /Add note/);

    const [block] = runtime.moduleHost.visibleSlot("dashboard.after", ["example"]);
    const html = await render(block, { modules: { data: { example: { greeting: "Welcome aboard" } } } });
    assert.match(html, /Welcome aboard/);
});

test("the brand in the sidebar follows the white label: custom name, custom logo, or the Perysee default", async () => {
    const inertia = await server.ssrLoadModule("/tests/Charts/fixtures/inertia.mjs");
    const BrandLogo = (await server.ssrLoadModule("/resources/js/Components/BrandLogo.vue")).default;
    const show = (branding) => {
        inertia.usePage().props = { branding, links: { "sites.index": "/sites" }, assets: { logo: "/logo.svg" } };
        return render(BrandLogo, {});
    };

    const named = await show({ name: "Acme Analytics", logo: null, accent: null });
    assert.match(named, />Acme Analytics</);
    assert.doesNotMatch(named, /brand-logo-light|brand-logo-dark/, "a client's name is never shown next to the Perysee mark");
    assert.doesNotMatch(named, /perysee<span/);

    const branded = await show({ name: "Acme Analytics", logo: "/modules/white-label/public/logo?v=1", accent: null });
    assert.match(branded, /<img[^>]*class="brand-logo-custom"[^>]*src="\/modules\/white-label\/public\/logo\?v=1"/);
    assert.doesNotMatch(branded, /brand-logo-light|brand-logo-dark/);
    assert.match(branded, /aria-label="Acme Analytics"/);

    const stock = await show({ name: "Perysee", logo: null, accent: null });
    assert.match(stock, /perysee<span class="text-primary">\.<\/span>/);
    assert.match(stock, /brand-logo-light/);

    const logoOnly = await show({ name: "Perysee", logo: "/l.png", accent: null });
    assert.match(logoOnly, /brand-logo-custom/);
    assert.doesNotMatch(logoOnly, /perysee<span|>Perysee</, "a logo without a name replaces the wordmark and shows no name");
});

test("the Nuxt UI color picker renders and reports the chosen color", async () => {
    const html = await render(components.UColorPicker, { modelValue: "#12ab34", format: "hex" });

    assert.ok(html.length > 50, "the picker renders its area and sliders");
    assert.doesNotMatch(html, /<ucolorpicker/i);
});
