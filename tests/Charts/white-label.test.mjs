import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import * as Vue from "vue";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createModuleHost } from "../../resources/js/module-host.mjs";

const source = readFileSync(new URL("../../modules/white-label/dist/module.js", import.meta.url), "utf8");

function load() {
    const calls = [];
    const router = {
        post: (...args) => calls.push(["post", ...args]),
        delete: (...args) => calls.push(["delete", ...args]),
    };
    const globals = {};
    const host = createModuleHost({ document: {}, globals, libraries: { Vue, Inertia: { router } } });
    vm.runInNewContext(source, globals);

    return { host, calls };
}

const stubs = {
    UCard: { render() { return h("section", this.$slots.default()); } },
    UFormField: { props: ["label", "hint", "error"], render() { return h("div", [h("label", this.label), h("small", this.hint), this.error ? h("p", { role: "alert" }, this.error) : null, this.$slots.default()]); } },
    UInput: { props: ["modelValue", "placeholder"], render() { return h("input", { value: this.modelValue, placeholder: this.placeholder }); } },
    UButton: { props: ["label"], render() { return h("button", this.$attrs, this.label ?? this.$slots.default?.()); } },
    UPopover: { render() { return h("div", { class: "popover" }, this.$slots.default?.()); } },
    UColorPicker: { render() { return h("div", { class: "picker" }); } },
};

const data = {
    name: "Acme Analytics",
    accent: "#12ab34",
    logo_url: "/modules/white-label/public/logo?v=1",
    has_logo: true,
    defaults: { name: "Perysee", accent: "#5B21FF" },
    links: { save: "/s", reset: "/s", logo: "/l" },
};

async function render(host, props) {
    const app = createSSRApp({ render: () => h(host.settingsPages["white-label"], props) });
    for (const [name, component] of Object.entries(stubs)) app.component(name, component);

    return renderToString(app);
}

test("the module registers the component of its own settings page", () => {
    const { host } = load();

    assert.ok(host.settingsPages["white-label"]);
    assert.deepEqual(Object.keys(host.slots), [], "it adds nothing to the existing screens");
});

test("the section shows the saved values, the logo and a way to remove it", async () => {
    const { host } = load();

    const html = await render(host, { modules: { data: { "white-label": data } }, errors: {} });

    assert.match(html, /White label/);
    assert.match(html, /value="Acme Analytics"/);
    assert.match(html, /value="#12ab34"/);
    assert.match(html, /src="\/modules\/white-label\/public\/logo\?v=1"/);
    assert.match(html, /Remove logo/);
    assert.match(html, /Reset everything to Perysee/);
    assert.equal((html.match(/Use default/g) ?? []).length, 2, "one way back to the default for the name and one for the color");
});

test("validation errors from the server appear next to their field", async () => {
    const { host } = load();

    const html = await render(host, { modules: { data: { "white-label": data } }, errors: { accent: "The accent must be a color such as #5B21FF." } });

    assert.match(html, /role="alert">The accent must be a color/);
});

test("without form data (a member, or the module data missing) nothing is rendered", async () => {
    const { host } = load();

    const html = await render(host, { modules: { data: { "white-label": [] } }, errors: {} });

    assert.doesNotMatch(html, /White label/);
});

test("without a logo the section says the Perysee logo is shown and offers no removal", async () => {
    const { host } = load();

    const html = await render(host, { modules: { data: { "white-label": { ...data, logo_url: null, has_logo: false } } }, errors: {} });

    assert.match(html, /the Perysee logo is shown/);
    assert.doesNotMatch(html, /Remove logo/);
});
