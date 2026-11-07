import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";
import { createRenderServer } from "./fixtures/render-server.mjs";

// The real layout, rendered with the real Nuxt UI dashboard components. It guards what the browser run
// measured: the page sits inside the dashboard shell, and no internal link carries a protocol (Nuxt UI would
// make it an external anchor and reload the whole document on every click).
let server;
let inertia;
let Layout;
let ui;
let registerModuleComponents;

before(async () => {
    // The site menu reads the origin to keep the chosen period in its links.
    globalThis.location = new URL("http://localhost/sites/1");
    server = await createRenderServer();
    inertia = await server.ssrLoadModule("/tests/Charts/fixtures/inertia.mjs");
    Layout = (await server.ssrLoadModule("/resources/js/Components/WorkspaceLayout.vue")).default;
    ui = (await server.ssrLoadModule("@nuxt/ui/vue-plugin")).default;
    registerModuleComponents = (await server.ssrLoadModule("/resources/js/module-components.js")).registerModuleComponents;
});

after(async () => {
    await server?.close();
});

const signedIn = (extra = {}) => ({
    screen: "dashboard",
    auth: { id: 1, name: "Ada", email: "ada@example.test", is_admin: true },
    branding: { name: "Perysee", logo: null, accent: null },
    flash: {},
    assets: { logo: "/logo.svg", world: "/maps/world.json", brands: "/brands" },
    docsUrl: "https://docs.example.test",
    repositoryUrl: "https://github.example.test/perysee",
    currentUrl: "/dashboard",
    links: {
        dashboard: "/dashboard", "sites.index": "/sites", "sites.create": "/sites/create", health: "/health", settings: "/settings",
        modules: "/settings/modules", "password.edit": "/account/password", logout: "/logout",
    },
    navigation: [{ id: 1, name: "Acme", domain: "acme.test", url: "/sites/1", favicon_url: "/sites/1/favicon" }],
    modules: { navigation: { workspace: [{ label: "Notes", icon: "i-tabler-note", url: "/modules/notes" }], admin: [{ label: "Audit", icon: "i-tabler-shield", url: "/modules/audit" }], site: [] }, data: {}, active: [], scripts: [], styles: [] },
    ...extra,
});

async function render(props) {
    inertia.usePage().props = props;
    inertia.usePage().url = props.currentUrl ?? "/";
    const app = createSSRApp({ render: () => h(Layout, null, { default: () => h("p", { id: "page-content" }, "Page body") }) });
    app.use(ui);
    registerModuleComponents(app);

    return renderToString(app);
}

test("the page sits in the Nuxt UI dashboard shell: sidebar, top bar, and a scroll region for the content", async () => {
    const html = await render(signedIn());

    assert.match(html, /perysee-sidebar/);
    assert.match(html, /perysee-topbar/);
    assert.match(html, /<main[^>]*id="main"[^>]*scroll-region|<main[^>]*scroll-region[^>]*id="main"/, "Inertia restores the scroll of an element marked scroll-region");
    assert.match(html, /<main[^>]*>.*<p id="page-content">Page body<\/p>/s, "the page is the content of the scrolling element");
    assert.match(html, /aria-label="Open navigation"/, "the top bar carries the button that opens the menu on a phone");
    assert.doesNotMatch(html, /perysee-shell"[^>]*>\s*<aside/, "the hand-made aside is gone");
});

test("no link of the application carries a protocol, so every click stays an Inertia visit", async () => {
    const html = await render(signedIn());
    const hrefs = [...html.matchAll(/<a\b[^>]*\bhref="([^"]*)"/g)].map((match) => match[1]);

    assert.ok(hrefs.length > 8, `expected the menu links to be rendered, found ${hrefs.length}`);
    const external = hrefs.filter((href) => /^[a-z][a-z0-9+.-]*:/i.test(href));
    assert.deepEqual(
        [...new Set(external)].sort(),
        ["https://docs.example.test", "https://github.example.test/perysee"],
        "only the documentation and the repository, which live on other sites, may be absolute",
    );
});

test("module entries appear in the menu and the active page is marked", async () => {
    const html = await render(signedIn());

    assert.match(html, /href="\/modules\/notes"/);
    assert.match(html, /href="\/modules\/audit"/);
    assert.match(html, /aria-current="page"[^>]*href="\/dashboard"|href="\/dashboard"[^>]*aria-current="page"/);
});

test("the top bar shows the white label name and members do not get the administration entries", async () => {
    const html = await render(signedIn({ auth: { id: 2, name: "Bo", email: "bo@example.test", is_admin: false }, branding: { name: "Acme Analytics", logo: null, accent: null } }));

    assert.match(html, />Acme Analytics</);
    assert.doesNotMatch(html, /Instance health|href="\/settings\/modules"/);
});

test("a visitor who is not signed in gets the plain sign-in shell, not the dashboard", async () => {
    const html = await render({ ...signedIn({ screen: "auth.login" }), auth: null, modules: { navigation: { workspace: [], admin: [], site: [] }, data: {}, active: [], scripts: [], styles: [] } });

    assert.match(html, /auth-shell/);
    assert.doesNotMatch(html, /perysee-sidebar|perysee-topbar/);
});

const inSite = () =>
    signedIn({
        screen: "sites.show",
        currentUrl: "/sites/1",
        website: { id: 1, name: "Acme", domain: "acme.test" },
        canUpdate: true,
        siteLinks: Object.fromEntries(["show", "pages", "sources", "campaigns", "devices", "countries", "sessions", "events", "funnels", "goals", "journeys", "realtime", "tracking", "edit", "favicon"].map((key) => [key, `/sites/1${key === "show" ? "" : `/${key}`}`])),
    });

test("Home is the first entry of the menu, inside a site as well, so there is always a way back", async () => {
    for (const [label, props] of [["outside a site", signedIn()], ["inside a site", inSite()]]) {
        const html = await render(props);

        assert.match(html, /<a[^>]*href="\/dashboard"[^>]*>(?:(?!<\/a>).)*Home/s, `Home links to the home page ${label}`);
        assert.doesNotMatch(html, />Dashboard</, `the old name is gone ${label}`);
    }
});

test("inside a site the menu still lists the site sections after Home", async () => {
    const html = await render(inSite());

    assert.ok(html.indexOf("Home") < html.indexOf("Overview"), "Home comes before the site sections");
    assert.match(html, /href="\/sites\/1\/pages"/);
});

test("the command palette has a single entry point, the search field of the sidebar", async () => {
    const html = await render(signedIn());

    assert.equal((html.match(/aria-keyshortcuts="Meta\+K Control\+K"/g) ?? []).length, 1);
    assert.doesNotMatch(html, /Open the command palette/);
});
