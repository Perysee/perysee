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

test("the page sits in the Nuxt UI dashboard shell: a sidebar and a scroll region, without a top bar", async () => {
    const html = await render(signedIn());

    assert.match(html, /perysee-sidebar/);
    assert.doesNotMatch(html, /perysee-topbar/, "the top bar is gone: its content lives in the sidebar");
    assert.match(html, /<main[^>]*id="main"[^>]*scroll-region|<main[^>]*scroll-region[^>]*id="main"/, "Inertia restores the scroll of an element marked scroll-region");
    assert.match(html, /<main[^>]*>.*<p id="page-content">Page body<\/p>/s, "the page is the content of the scrolling element");
    assert.match(html, /aria-label="Open navigation"/, "the content keeps the button that opens the menu on a phone");
    assert.doesNotMatch(html, /perysee-shell"[^>]*>\s*<aside/, "the hand-made aside is gone");
});

test("no link of the application carries a protocol, so every click stays an Inertia visit", async () => {
    const html = await render(signedIn());
    const hrefs = [...html.matchAll(/<a\b[^>]*\bhref="([^"]*)"/g)].map((match) => match[1]);

    assert.ok(hrefs.length > 8, `expected the menu links to be rendered, found ${hrefs.length}`);
    const external = hrefs.filter((href) => /^[a-z][a-z0-9+.-]*:/i.test(href));
    assert.deepEqual(
        [...new Set(external)].sort(),
        ["https://docs.example.test"],
        "only the documentation, which lives on another site, may be absolute (the repository sits in the closed account menu)",
    );
});

test("module entries appear in the menu and the active page is marked", async () => {
    const html = await render(signedIn());

    assert.match(html, /href="\/modules\/notes"/);
    assert.match(html, /href="\/modules\/audit"/);
    assert.match(html, /aria-current="page"[^>]*href="\/dashboard"|href="\/dashboard"[^>]*aria-current="page"/);
});

test("the sidebar shows the white label name and members do not get the administration entries", async () => {
    const html = await render(signedIn({ auth: { id: 2, name: "Bo", email: "bo@example.test", is_admin: false }, branding: { name: "Acme Analytics", logo: null, accent: null } }));

    assert.match(html, />Acme Analytics</);
    assert.doesNotMatch(html, /Instance health|href="\/settings\/modules"/);
});

test("administrators get a Mail entry in the administration menu, members do not", async () => {
    const links = { ...signedIn().links, "settings.mail": "/settings/mail" };
    const admin = await render(signedIn({ links, screen: "settings.mail", currentUrl: "/settings/mail" }));
    assert.match(admin, /aria-current="page"[^>]*href="\/settings\/mail"|href="\/settings\/mail"[^>]*aria-current="page"/);

    const member = await render(signedIn({ links, auth: { id: 2, name: "Bo", email: "bo@example.test", is_admin: false } }));
    assert.doesNotMatch(member, /href="\/settings\/mail"/);
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

test("inside a site, the site switcher heads the sidebar and lists every site", async () => {
    const html = await render(inSite());
    const sidebar = html.slice(html.indexOf("perysee-sidebar"));

    assert.match(sidebar, /aria-label="Switch site, current: Acme"/, "the switcher names the current site");
    assert.match(sidebar, /acme\.test/, "the switcher shows the domain under the name");
    assert.ok(sidebar.indexOf("Switch site") < sidebar.indexOf("Search"), "the switcher comes before the search field");
});

test("the sidebar footer carries the account, the colour mode and the documentation", async () => {
    const html = await render(signedIn());
    const footer = html.slice(html.lastIndexOf("Account menu") - 2000);

    assert.match(footer, /aria-label="Account menu"/);
    assert.match(footer, /aria-label="Switch (to (dark|light)|color) mode"/, "the colour mode button (stubbed in this fixture)");
    assert.match(footer, /aria-label="Documentation"[^>]*href="https:\/\/docs\.example\.test"|href="https:\/\/docs\.example\.test"[^>]*aria-label="Documentation"/);
});
