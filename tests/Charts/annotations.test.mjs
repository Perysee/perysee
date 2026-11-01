import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import * as Vue from "vue";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createModuleHost } from "../../resources/js/module-host.mjs";

const source = readFileSync(new URL("../../modules/annotations/dist/module.js", import.meta.url), "utf8");

function load() {
    const globals = {};
    const Link = { props: ["href"], render() { return h("a", { href: this.href }, this.$slots.default()); } };
    const host = createModuleHost({ document: {}, globals, libraries: { Vue, Inertia: { router: { post() {}, delete() {} }, Link } } });
    vm.runInNewContext(source, globals);

    return host;
}

const stubs = {
    UCard: { render() { return h("section", this.$slots.default()); } },
    UFormField: { props: ["label", "error"], render() { return h("div", [h("label", this.label), this.error ? h("p", { role: "alert" }, this.error) : null, this.$slots.default()]); } },
    UInput: { props: ["modelValue"], render() { return h("input", { value: this.modelValue }); } },
    UButton: { props: ["label"], render() { return h("button", this.$attrs, this.label); } },
};

async function render(component, props) {
    const app = createSSRApp({ render: () => h(component, props) });
    for (const [name, stub] of Object.entries(stubs)) app.component(name, stub);

    return renderToString(app);
}

const notes = [
    { id: 4, noted_on: "2026-10-04", body: "Pricing page live", author: "Ada" },
    { id: 3, noted_on: "2026-10-03", body: "Newsletter sent", author: null },
];
const page = { website: { name: "Acme" }, notes, canWrite: true, links: { store: "/modules/annotations/sites/1/notes" }, errors: {} };

test("the module registers a screen, a slot restricted to its own module and a settings page", () => {
    const host = load();

    assert.ok(host.screens["module:annotations"]);
    assert.ok(host.settingsPages.annotations);
    assert.equal(host.slots["site.report.after"].length, 1);
    assert.equal(host.slots["site.report.after"][0].module, "annotations");
});

test("the page lists the notes with their date and author and offers to add and delete when allowed", async () => {
    const html = await render(load().screens["module:annotations"], page);

    assert.match(html, /<h1>Annotations<\/h1>/);
    assert.match(html, /Pricing page live/);
    assert.match(html, /2026-10-04 · Ada/);
    assert.match(html, /Add note/);
    assert.match(html, /aria-label="Delete note"/);
});

test("a viewer sees the notes but no form and no delete button", async () => {
    const html = await render(load().screens["module:annotations"], { ...page, canWrite: false });

    assert.match(html, /Newsletter sent/);
    assert.doesNotMatch(html, /Add note/);
    assert.doesNotMatch(html, /Delete note/);
});

test("an empty site says so, and validation errors show next to their field", async () => {
    const screen = load().screens["module:annotations"];

    assert.match(await render(screen, { ...page, notes: [] }), /No annotation yet\./);
    assert.match(await render(screen, { ...page, errors: { body: "The note is required." } }), /role="alert">The note is required\./);
});

test("the block under the reports shows the latest notes and a link to all of them", async () => {
    const [{ component }] = load().slots["site.report.after"];

    const html = await render(component, { modules: { data: { annotations: { recent: notes, page: "/modules/annotations/sites/1" } } } });

    assert.match(html, /Latest annotations/);
    assert.match(html, /href="\/modules\/annotations\/sites\/1"/);
    assert.match(html, /Newsletter sent/);
});

test("the block renders nothing when the site has no note or the module has no data", async () => {
    const [{ component }] = load().slots["site.report.after"];

    assert.doesNotMatch(await render(component, { modules: { data: { annotations: { recent: [], page: "/x" } } } }), /Latest annotations/);
    assert.doesNotMatch(await render(component, { modules: { data: {} } }), /Latest annotations/);
});

test("the settings page is empty for members, who receive no settings", async () => {
    const settings = load().settingsPages.annotations;

    assert.match(await render(settings, { modules: { data: { annotations: { settings: { retention_days: 400, links: { save: "/s" } } } } }, errors: {} }), /Keep notes for/);
    assert.doesNotMatch(await render(settings, { modules: { data: { annotations: { settings: null } } }, errors: {} }), /Keep notes for/);
});
