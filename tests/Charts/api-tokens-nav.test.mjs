import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createRenderServer } from "./fixtures/render-server.mjs";

// The "API access" entry belongs to site owners only, and the page that opens from it renders without leaking the secret.
let server;
let inertia;
let Layout;
let Page;
let ui;

before(async () => {
    globalThis.location = new URL("http://localhost/sites/1");
    server = await createRenderServer();
    inertia = await server.ssrLoadModule("/tests/Charts/fixtures/inertia.mjs");
    Layout = (await server.ssrLoadModule("/resources/js/Components/WorkspaceLayout.vue")).default;
    Page = (await server.ssrLoadModule("/resources/js/Components/ApiTokensPage.vue")).default;
    ui = (await server.ssrLoadModule("@nuxt/ui/vue-plugin")).default;
});

after(async () => {
    await server?.close();
});

const props = (extra = {}) => ({
    screen: "sites.api-tokens",
    auth: { id: 1, name: "Ada", email: "ada@example.test", is_admin: false },
    branding: { name: "Perysee", logo: null, accent: null },
    flash: {},
    assets: { logo: "/logo.svg", world: "/maps/world.json", brands: "/brands" },
    docsUrl: "https://docs.example.test",
    repositoryUrl: "https://github.example.test/perysee",
    currentUrl: "/sites/1/api-tokens",
    links: { dashboard: "/dashboard", "sites.index": "/sites", "sites.create": "/sites/create", health: "/health", settings: "/settings", modules: "/settings/modules", "password.edit": "/account/password", logout: "/logout" },
    navigation: [{ id: 1, name: "Acme", domain: "acme.test", url: "/sites/1", favicon_url: "/sites/1/favicon" }],
    modules: { navigation: { workspace: [], admin: [], site: [] }, data: {}, active: [], scripts: [], styles: [] },
    website: { id: 1, name: "Acme", domain: "acme.test" },
    canUpdate: true,
    canShare: true,
    siteLinks: Object.fromEntries(["show", "pages", "sources", "campaigns", "devices", "countries", "sessions", "events", "funnels", "goals", "journeys", "realtime", "tracking", "edit", "favicon", "sharing", "team", "api-tokens"].map((key) => [key, `/sites/1${key === "show" ? "" : `/${key}`}`])),
    ...extra,
});

async function render(pageProps, withPage = false) {
    inertia.usePage().props = pageProps;
    inertia.usePage().url = pageProps.currentUrl;
    const app = createSSRApp({ render: () => h(Layout, null, { default: () => (withPage ? h(Page, pageProps) : h("p", "body")) }) });
    app.use(ui);

    return renderToString(app);
}

test("the menu lists API access for an owner, as a path and not an external link", async () => {
    const html = await render(props({ canManageApiTokens: true }));

    assert.match(html, /<a[^>]*href="\/sites\/1\/api-tokens"[^>]*>(?:(?!<\/a>).)*API access/s);
    assert.doesNotMatch(html, /href="https?:\/\/[^"]*api-tokens"/);
});

test("the menu hides API access from anyone who cannot manage tokens", async () => {
    const html = await render(props({ canManageApiTokens: false }));

    assert.doesNotMatch(html, /API access/);
});

test("the page shows a new token once, with a copy button, and lists tokens without any secret", async () => {
    const secret = "psy_" + "A".repeat(43);
    const base = {
        apiTokens: [{ id: 5, label: "WordPress", hint: "AAAAAA", scopes: ["overview", "pages"], rate_per_minute: 60, status: "active", expires_at: null, revoked_at: null, last_used_at: null, use_count: 0, created_at: "2026-11-08T10:00:00Z", created_by: "Ada", revoke_url: "/sites/1/api-tokens/5" }],
        scopeOptions: ["overview", "pages", "goals"],
        rateLimits: { default: 60, min: 10, max: 300 },
        apiBase: "https://stats.example.test/api/v1",
        canManageApiTokens: true,
    };
    const created = await render(props({ ...base, created: { token: secret } }), true);
    assert.match(created, /Token created/);
    assert.ok(created.includes(secret));
    assert.match(created, /Copy token/);
    assert.match(created, /Authorization: Bearer/);

    const listed = await render(props({ ...base, created: null }), true);
    assert.ok(!listed.includes(secret));
    assert.match(listed, /WordPress/);
    assert.match(listed, /Revoke/);
    assert.match(listed, /Ends with/);
});
