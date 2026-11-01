import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import * as Vue from "vue";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createModuleHost } from "../../resources/js/module-host.mjs";

const source = readFileSync(new URL("../../modules/example/dist/module.js", import.meta.url), "utf8");

function load() {
    const globals = {};
    const host = createModuleHost({ document: {}, globals, libraries: { Vue, Inertia: { router: { post() {} } } } });
    vm.runInNewContext(source, globals);

    return host;
}

const stubs = {
    UCard: { render() { return h("section", this.$slots.default()); } },
    UFormField: { props: ["label", "error"], render() { return h("div", [h("label", this.label), this.error ? h("p", { role: "alert" }, this.error) : null, this.$slots.default()]); } },
    UInput: { props: ["modelValue"], render() { return h("input", { value: this.modelValue }); } },
    UButton: { props: ["label"], render() { return h("button", this.label); } },
};

async function render(component, props) {
    const app = createSSRApp({ render: () => h(component, props) });
    for (const [name, stub] of Object.entries(stubs)) app.component(name, stub);

    return renderToString(app);
}

test("the module adds a dashboard block bound to its own module, and a settings page", () => {
    const host = load();

    assert.equal(host.slots["dashboard.after"].length, 1);
    assert.equal(host.slots["dashboard.after"][0].module, "example");
    assert.ok(host.settingsPages.example);
});

test("the dashboard block shows the greeting and nothing without data", async () => {
    const [{ component }] = load().slots["dashboard.after"];

    assert.match(await render(component, { modules: { data: { example: { greeting: "Welcome aboard" } } } }), /Welcome aboard/);
    assert.equal((await render(component, { modules: { data: {} } })).includes("section"), false);
});

test("administrators get the form, members get nothing", async () => {
    const settings = load().settingsPages.example;

    const admin = await render(settings, { modules: { data: { example: { greeting: "Hi", save: "/modules/example/greeting" } } }, errors: {} });
    assert.match(admin, /Greeting/);
    assert.match(admin, /value="Hi"/);

    assert.equal((await render(settings, { modules: { data: { example: { greeting: "Hi", save: null } } }, errors: {} })).includes("Greeting"), false);
});

test("a validation error is shown next to the message field", async () => {
    const settings = load().settingsPages.example;

    const html = await render(settings, { modules: { data: { example: { greeting: "", save: "/s" } } }, errors: { greeting: "The greeting field is required." } });

    assert.match(html, /role="alert">The greeting field is required\./);
});
