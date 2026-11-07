import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createRenderServer } from "./fixtures/render-server.mjs";

let server;
let inertia;
let ui;
let Show;
let BreakdownTable;

before(async () => {
    server = await createRenderServer();
    inertia = await server.ssrLoadModule("/tests/Charts/fixtures/inertia.mjs");
    Show = (await server.ssrLoadModule("/resources/js/Pages/Share/Show.vue")).default;
    BreakdownTable = (await server.ssrLoadModule("/resources/js/Components/BreakdownTable.vue")).default;
    ui = (await server.ssrLoadModule("@nuxt/ui/vue-plugin")).default;
});

after(async () => {
    await server?.close();
});

// What the share routes expose: no links, no navigation, no site id (see HandleInertiaRequests::share).
const sharedProps = { currentUrl: "/s/token", assets: { logo: "/logo.svg", world: "/maps/world.json", brands: "/brands" }, flash: { status: null, error: null }, errors: {} };

async function render(component, props) {
    inertia.usePage().props = sharedProps;
    inertia.usePage().url = "/s/token";
    const app = createSSRApp({ render: () => h(component, props) });
    app.use(ui);

    return renderToString(app);
}

const page = {
    site: { name: "Acme", domain: "acme.test", timezone: "UTC" },
    sections: ["overview", "pages"],
    period: { from: "2026-10-26", to: "2026-11-01" },
    today: "2026-11-01",
    report: { totals: { pageviews: 5, sessions: null, custom_events: 1 }, freshness: { aggregated_at: null } },
    series: [],
    dimensionOptions: ["path", "source"],
    dimension: "path",
    dimensionReport: { data: [{ value: "/pricing", count: 3 }, { value: "/about", count: 2 }], total: 5, series: [] },
};

test("the shared page lists the rows of a report as plain text, with no link into the application", async () => {
    const html = await render(Show, page);
    assert.match(html, /\/pricing/);
    assert.match(html, /\/about/);
    assert.doesNotMatch(html, /href="\/sites/);
    assert.doesNotMatch(html, /<a [^>]*class="breakdown-label"/);
});

test("a breakdown table without a report URL renders its rows and no link, compact or not", async () => {
    for (const compact of [false, true]) {
        const html = await render(BreakdownTable, { rows: [{ value: "/pricing", count: 3 }], total: 3, type: "path", compact });
        assert.match(html, /\/pricing/);
        assert.doesNotMatch(html, /<a /);
        assert.doesNotMatch(html, /View report/);
    }
});
