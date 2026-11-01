import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createRenderServer } from "./fixtures/render-server.mjs";

let server;
let inertia;
let Home;
let ui;
let registerModuleComponents;

before(async () => {
    server = await createRenderServer();
    inertia = await server.ssrLoadModule("/tests/Charts/fixtures/inertia.mjs");
    Home = (await server.ssrLoadModule("/resources/js/Components/DashboardPage.vue")).default;
    ui = (await server.ssrLoadModule("@nuxt/ui/vue-plugin")).default;
    registerModuleComponents = (await server.ssrLoadModule("/resources/js/module-components.js")).registerModuleComponents;
});

after(async () => {
    await server?.close();
});

const period = { from: "2026-10-26", to: "2026-11-01" };
const site = (id, name, extra = {}) => ({ id, name, domain: `${name.toLowerCase()}.test`, timezone: "UTC", url: `/sites/${id}`, tracking_url: `/sites/${id}/tracking`, first_collected_at: "2026-10-01T00:00:00Z", is_demo: false, ...extra });
const summary = (pageviews, sessions, events, pending = false) => ({ totals: { pageviews, sessions, custom_events: events }, freshness: { pending }, availability: { sessions: sessions === null ? "unavailable" : "available" } });

const props = (extra = {}) => ({
    sites: { data: [site(1, "Busy"), site(2, "Quiet"), site(3, "Fresh", { first_collected_at: null })], total: 3, last_page: 1, current_page: 1, per_page: 20 },
    summaries: { 1: summary(1000, null, 50), 2: summary(0, 0, 0), 3: summary(0, 0, 0) },
    charts: { 1: [], 2: [], 3: [] },
    period,
    today: "2026-11-01",
    sitePeriods: { 1: { ...period, timezone: "UTC" }, 2: { ...period, timezone: "UTC" }, 3: { ...period, timezone: "UTC" } },
    comparisons: {},
    attention: [],
    ...extra,
});

async function render(pageProps) {
    inertia.usePage().props = { links: { dashboard: "/dashboard", "sites.create": "/sites/create" } };
    const app = createSSRApp({ render: () => h(Home, pageProps) });
    app.use(ui);
    registerModuleComponents(app);

    return renderToString(app);
}

test("the page is called Home and sums the activity of the sites it lists", async () => {
    const html = await render(props());

    assert.match(html, /<h1>Home<\/h1>/);
    assert.doesNotMatch(html, />Dashboard</);
    assert.match(html, /3 sites/);
    assert.match(html, /1,000<\/strong>\s*pageviews/);
    assert.match(html, /50<\/strong>\s*custom events/);
});

test("a single site is not pluralised", async () => {
    const html = await render(props({ sites: { data: [site(1, "Busy")], total: 1, last_page: 1, current_page: 1, per_page: 20 } }));

    assert.match(html, /1 site\b/);
    assert.doesNotMatch(html, /1 sites/);
});

test("an administrator is told when the scheduler is silent, with the way to the health page", async () => {
    const html = await render(props({ attention: [{ code: "scheduler", message: "The scheduler has never run on this instance.", link: "/health" }] }));

    assert.match(html, /The scheduler has never run on this instance\./);
    assert.match(html, /href="\/health"/);
    assert.match(html, /Instance health/);
});

test("nothing is shown when there is nothing to look at", async () => {
    const html = await render(props());

    assert.doesNotMatch(html, /scheduler/i);
    assert.doesNotMatch(html, /Instance health/);
});

test("a site that never received an event says so and offers the tracking setup, a site with traffic does not", async () => {
    const html = await render(props());

    assert.match(html, /Waiting for the first event/);
    assert.equal((html.match(/Waiting for the first event/g) ?? []).length, 1, "only the site that never collected");
    assert.match(html, /href="\/sites\/3\/tracking"/);
    assert.doesNotMatch(html, /href="\/sites\/1\/tracking"|href="\/sites\/2\/tracking"/);
});

test("a site that collected before but is quiet in this period keeps the plain message", async () => {
    const html = await render(props());

    assert.match(html, /No traffic in this period/);
});

test("demonstration sites never ask for a tracking setup", async () => {
    const html = await render(props({ sites: { data: [site(4, "Demo", { first_collected_at: null, is_demo: true })], total: 1, last_page: 1, current_page: 1, per_page: 20 }, summaries: { 4: summary(10, 5, 2) }, charts: { 4: [] }, sitePeriods: { 4: { ...period, timezone: "UTC" } } }));

    assert.doesNotMatch(html, /Waiting for the first event/);
});

test("without any site the page offers to add the first one", async () => {
    const html = await render(props({ sites: { data: [], total: 0, last_page: 1, current_page: 1, per_page: 20 }, summaries: {}, charts: {}, sitePeriods: {} }));

    assert.match(html, /Add your first site/);
    assert.doesNotMatch(html, /pageviews/);
});
