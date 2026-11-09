import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createRenderServer } from "./fixtures/render-server.mjs";

// The report header: one row with the title and every control, then the facts about the data as separate items.
let server;
let ReportHeader;
let ui;

before(async () => {
    server = await createRenderServer();
    ReportHeader = (await server.ssrLoadModule("/resources/js/Components/ReportHeader.vue")).default;
    ui = (await server.ssrLoadModule("@nuxt/ui/vue-plugin")).default;
});

after(async () => {
    await server?.close();
});

async function render(props, slots = {}) {
    const app = createSSRApp({ render: () => h(ReportHeader, props, slots) });
    app.use(ui);
    return renderToString(app);
}

test("the facts about the data are separate items, never joined by middle dots", async () => {
    const html = await render({ title: "Goals", demo: true, meta: ["acme.test", "Sessions that reach a page or an event", null] });

    assert.match(html, /<h1[^>]*>Goals<\/h1>/);
    assert.match(html, /Demonstration data/, "demonstration data is a badge");
    assert.match(html, /<span[^>]*>acme\.test<\/span>/);
    assert.match(html, /<span[^>]*>Sessions that reach a page or an event<\/span>/);
    assert.doesNotMatch(html, /·/, "no middle dot");
});

test("real data shows no badge and the controls sit in the header row", async () => {
    const html = await render({ title: "Funnels", meta: ["acme.test"] }, { default: () => h("button", { id: "tool" }, "Create funnel") });

    assert.doesNotMatch(html, /Demonstration data/);
    assert.match(html, /report-head-actions[^>]*>.*<button id="tool">/s);
});
