<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { useToast, defineShortcuts } from "@nuxt/ui/composables";
import { queryUrl, reportFilters } from "../interface-query.mjs";
import { createBusyTracker, httpErrorToast, networkErrorToast } from "../navigation-feedback.mjs";
import { remember, restore } from "../period-memory.mjs";
import { groupedItems, limitSites } from "../navigation.mjs";
import BrandLogo from "./BrandLogo.vue";
import ColorModeButton from "./ColorModeButton.vue";
import CommandPalette from "./CommandPalette.vue";
import { reportItems } from "../palette.mjs";
import { applyColorMode } from "../color-mode.mjs";
import { siteAvatar } from "../site-avatar.mjs";
const page = usePage(),
    siteSearch = ref("");
const p = computed(() => page.props);
const title = computed(
    () =>
        ({
            "sites.index": "Sites",
            "sites.form": p.value.website?.id ? "Site settings" : "Add site",
            "sites.show": p.value.section
                ? {
                      pages: "Pages",
                      sources: "Sources",
                      campaigns: "Campaigns",
                      devices: "Devices & browsers",
                      countries: "Countries",
                      events: "Events",
                      sessions: "Sessions",
                      realtime: "Recent activity",
                  }[p.value.section]
                : p.value.website?.name || "Overview",
            "sites.tracking": "Tracking setup",
            "sites.sharing": "Sharing",
            "sites.api-tokens": "API access",
            "sites.team": "Team",
            invitation: "Invitation",
            funnels: "Funnels",
            goals: "Goals",
            "goal.report": p.value.goal?.name || "Goal",
            "funnel.report": p.value.funnel?.name || "Funnel",
            journeys: "Journeys",
            dashboard: "Home",
            health: "Instance health",
            settings: "Instance settings",
            "settings.mail": "Mail",
            modules: "Modules",
            "modules.settings": "Module settings",
            "auth.login": "Sign in",
            "auth.password": "Password",
            install: "Install Perysee",
        })[p.value.screen] ||
        p.value.title ||
        "Perysee",
);
const hasSite = computed(
    () => p.value.website?.id && p.value.screen !== "sites.index",
);
const moduleItems = (zone) =>
    (p.value.modules?.navigation?.[zone] ?? []).map((item) => ({
        label: item.label,
        icon: item.icon,
        to: item.url,
        active: p.value.currentUrl === item.url,
    }));
// The way back to the home page, offered everywhere, inside a site included.
const homeItem = computed(() => ({
    label: "Home",
    icon: "i-tabler-home",
    to: p.value.links.dashboard,
    active: p.value.screen === "dashboard",
}));
const workspaceNav = computed(() => [
    homeItem.value,
    {
        label: "All sites",
        icon: "i-tabler-world",
        to: p.value.links["sites.index"],
        active: p.value.screen === "sites.index",
    },
    ...moduleItems("workspace"),
]);
const siteModules = computed(() =>
    (p.value.modules?.menu ?? []).map((item) => ({
        label: item.label,
        icon: item.icon,
        to: item.url,
        active: p.value.currentUrl === item.url,
    })),
);
const matchingSites = computed(() =>
    p.value.navigation.filter((site) =>
        (site.name + " " + site.domain)
            .toLowerCase()
            .includes(siteSearch.value.toLowerCase()),
    ),
);
const quickSites = computed(() => {
    const { visible, hidden } = limitSites(matchingSites.value, 8);
    return [
        ...visible.map((site) => ({
            label: site.name,
            to: site.url,
            avatar: siteAvatar(site.favicon_url, site.name),
            active: site.id === p.value.website?.id,
        })),
        ...(hidden
            ? [
                  {
                      label: `View all ${matchingSites.value.length} sites`,
                      to: p.value.links["sites.index"],
                      icon: "i-tabler-arrow-right",
                  },
              ]
            : []),
    ];
});
const openFirstSite = () => {
    const first = matchingSites.value[0];
    if (first) router.visit(first.url);
};
const nav = computed(() => {
    if (hasSite.value) {
        const values = {...reportFilters(page.url)};
        delete values.value;
        delete values.dimension;
        const dates = new URLSearchParams();
        const params = new URL(page.url, location.origin).searchParams;
        for (const key of ["from", "to", "period"])
            if (params.has(key)) dates.set(key, params.get(key));
        const moduleEntries = moduleItems("site");
        return [homeItem.value, ...groupedItems(({ key, label, icon, keywords }) => ({
            label,
            icon,
            keywords,
            to:
                queryUrl(p.value.siteLinks[key === "overview" ? "show" : key], key === "realtime" ? {} : {...values, ...Object.fromEntries(dates)}),
            active: key === "goals" ? ["goals","goal.report"].includes(p.value.screen) : key === "funnels" ? ["funnels","funnel.report"].includes(p.value.screen) : key === "journeys" ? p.value.screen === "journeys" : p.value.screen === "sites.show" && (p.value.section || "overview") === key,
        })),
        ...(moduleEntries.length ? [{ label: "Modules", type: "label" }, ...moduleEntries] : []),
        ];
    }
    return workspaceNav.value;
});
const configNav = computed(() =>
    hasSite.value
        ? [
              {
                  label: "Tracking setup",
                  icon: "i-tabler-code",
                  to: p.value.siteLinks.tracking,
                  active: p.value.screen === "sites.tracking",
              },
              ...(p.value.canShare
                  ? [
                        {
                            label: "Sharing",
                            icon: "i-tabler-share",
                            to: p.value.siteLinks.sharing,
                            active: p.value.screen === "sites.sharing",
                        },
                    ]
                  : []),
              ...(p.value.canManageApiTokens
                  ? [
                        {
                            label: "API access",
                            icon: "i-tabler-key",
                            to: p.value.siteLinks["api-tokens"],
                            active: p.value.screen === "sites.api-tokens",
                        },
                    ]
                  : []),
              {
                  label: "Team",
                  icon: "i-tabler-users",
                  to: p.value.siteLinks.team,
                  active: p.value.screen === "sites.team",
              },
              ...(p.value.canUpdate
                  ? [
                        {
                            label: "Site settings",
                            icon: "i-tabler-settings",
                            to: p.value.siteLinks.edit,
                            active: p.value.screen === "sites.form",
                        },
                    ]
                  : []),
          ]
        : [
              ...(p.value.auth?.is_admin
                  ? [
                        {
                            label: "Instance health",
                            icon: "i-tabler-activity",
                            to: p.value.links.health,
                            active: p.value.screen === "health",
                        },
                        {
                            label: "Instance settings",
                            icon: "i-tabler-settings",
                            to: p.value.links.settings,
                            active: p.value.screen === "settings",
                        },
                        {
                            label: "Mail",
                            icon: "i-tabler-mail",
                            to: p.value.links["settings.mail"],
                            active: p.value.screen === "settings.mail",
                        },
                        {
                            label: "Modules",
                            icon: "i-tabler-puzzle",
                            to: p.value.links.modules,
                            active: p.value.screen === "modules",
                        },
                        ...moduleItems("admin"),
                    ]
                  : []),
              {
                  label: "Documentation",
                  icon: "i-tabler-book-2",
                  to: p.value.docsUrl,
                  external: true,
              },
          ],
);
const siteMenu = computed(() => [
    p.value.navigation.map((site) => ({
        label: site.name,
        to: site.url,
        avatar: siteAvatar(site.favicon_url, site.name),
    })),
    [
        {
            label: "Home",
            to: p.value.links.dashboard,
            icon: "i-tabler-home",
        },
        {
            label: "All sites",
            to: p.value.links["sites.index"],
            icon: "i-tabler-world",
        },
    ],
]);
const accountMenu = computed(() => [
    [{ label: p.value.auth?.email, type: "label" }],
    [
        {
            label: "Password",
            to: p.value.links["password.edit"],
            icon: "i-tabler-key",
        },
        ...(p.value.auth?.is_admin
            ? [
                  {
                      label: "Instance health",
                      to: p.value.links.health,
                      icon: "i-tabler-activity",
                  },
              ]
            : []),
    ],
    [
        {
            label: "Sign out",
            icon: "i-tabler-logout",
            onSelect: () => router.post(p.value.links.logout),
        },
    ],
]);
const paletteOpen = ref(false);
const toggleColorMode = () => {
    let storage = null;
    try {
        storage = localStorage;
    } catch {
        // Appearance still works when storage is unavailable.
    }
    applyColorMode(!document.documentElement.classList.contains("dark"), document.documentElement, storage);
    window.dispatchEvent(new Event("perysee:color-mode"));
};
const hasFilterBar = computed(
    () => hasSite.value && p.value.screen === "sites.show" && p.value.section !== "realtime",
);
const paletteGroups = computed(() => {
    const report = reportItems({
        url: page.url,
        currentUrl: p.value.currentUrl,
        period: p.value.period,
        siteLinks: hasSite.value && p.value.screen === "sites.show" ? p.value.siteLinks : null,
        overview: !p.value.section || p.value.section === "overview",
        brands: p.value.assets?.brands,
    });
    return [
        { id: "sections", label: "This site", items: hasSite.value ? nav.value.filter((item) => item.type !== "label").map((item) => (item.active ? { ...item, suffix: "Current" } : item)) : [] },
        { id: "period", label: "Period", items: report.period },
        { id: "filters", label: "AI sources and browsers", items: report.filters },
        {
            id: "sites",
            label: "Sites",
            items: p.value.navigation.map((site) => ({
                label: site.name,
                suffix: site.id === p.value.website?.id ? `${site.domain} · Current` : site.domain,
                active: site.id === p.value.website?.id,
                keywords: [site.domain, "site"],
                to: site.url,
                avatar: siteAvatar(site.favicon_url, site.name),
            })),
        },
        {
            id: "actions",
            label: "Actions",
            items: [
                ...(p.value.links?.dashboard
                    ? [{ label: "Home", icon: "i-tabler-home", keywords: ["dashboard", "accueil", "overview"], to: p.value.links.dashboard, kbds: ["g", "h"], ...(p.value.screen === "dashboard" ? { active: true, suffix: "Current" } : {}) }]
                    : []),
                { label: "All sites", icon: "i-tabler-world", keywords: ["sites", "list"], to: p.value.links["sites.index"], kbds: ["g", "s"], ...(p.value.screen === "sites.index" ? { active: true, suffix: "Current" } : {}) },
                { label: "Add site", icon: "i-tabler-plus", keywords: ["new", "create", "ajouter", "nouveau"], to: p.value.links["sites.create"] },
                ...(hasFilterBar.value
                    ? [{ label: "Add a filter", icon: "i-tabler-filter-plus", keywords: ["filter", "filtre", "segment"], kbds: ["/"], run: () => window.dispatchEvent(new Event("perysee:open-filter")) }]
                    : []),
                { label: "Toggle light / dark mode", icon: "i-tabler-moon", keywords: ["theme", "dark", "light", "sombre", "clair", "appearance"], run: toggleColorMode },
                { label: "Sign out", icon: "i-tabler-logout", keywords: ["logout", "deconnexion"], run: () => router.post(p.value.links.logout) },
            ],
        },
    ];
});
const togglePalette = () => (paletteOpen.value = !paletteOpen.value);
defineShortcuts({
    meta_k: { usingInput: true, handler: togglePalette },
    ctrl_k: { usingInput: true, handler: togglePalette },
    "g-h": () => p.value.auth && router.visit(p.value.links.dashboard),
    "g-d": () => p.value.auth && router.visit(p.value.links.dashboard),
    "g-s": () => p.value.auth && router.visit(p.value.links["sites.index"]),
});
const toast = useToast(),
    busy = ref(false),
    tracker = createBusyTracker((value) => (busy.value = value));
watch(
    () => p.value.flash?.error,
    (error) => {
        if (error && p.value.auth)
            toast.add({ title: error, color: "error", icon: "i-tabler-alert-circle" });
    },
    { immediate: true },
);
watch(
    () => p.value.flash?.status,
    (status) => {
        if (status && p.value.auth)
            toast.add({ title: status, color: "success", icon: "i-tabler-circle-check" });
    },
    { immediate: true },
);
const localStorageOrNull = () => {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
};
function syncPeriod() {
    const site = p.value.website?.id;
    if (!site || p.value.screen !== "sites.show" || p.value.section === "realtime")
        return;
    const [path, search = ""] = page.url.split("?");
    const storage = localStorageOrNull();
    if (!storage) return;
    remember(storage, site, search);
    const restored = restore(storage, site, search);
    if (!restored) return;
    const next = new URLSearchParams(search);
    for (const [name, value] of new URLSearchParams(restored)) next.set(name, value);
    router.get(`${path}?${next}`, {}, { replace: true, preserveScroll: true });
}
const stops = [
    router.on("navigate", () => {
        syncPeriod();
    }),
    router.on("start", tracker.start),
    router.on("finish", tracker.finish),
    // Inertia 3 fires these two. Without a handler it shows the raw error page in a modal; keep that
    // modal while developing, where the exception page is useful.
    router.on("httpException", (event) => {
        if (!import.meta.env.DEV) event.preventDefault();
        toast.add(httpErrorToast(event.detail.response?.status));
    }),
    router.on("networkError", (event) => {
        event.preventDefault();
        toast.add(networkErrorToast());
    }),
];
onMounted(syncPeriod);
onBeforeUnmount(() => stops.forEach((stop) => stop()));
</script>
<template>
    <UApp>
        <Head :title="title" />
        <a href="#main" class="skip-link">Skip to content</a>
        <CommandPalette
            v-if="p.auth && p.screen !== 'install'"
            v-model:open="paletteOpen"
            :groups="paletteGroups"
        />
        <div v-if="!p.auth || p.screen === 'install'" class="auth-shell">
            <div class="mb-9 flex items-center gap-6">
                <BrandLogo /><ColorModeButton />
            </div>
            <UAlert
                v-if="p.flash.status"
                color="success"
                variant="soft"
                :description="p.flash.status"
                class="max-w-xl mb-5"
            />
            <main
                id="main"
                :class="
                    p.screen === 'install' ? 'w-full max-w-2xl' : 'auth-card'
                "
            >
                <slot />
            </main>
            <p class="text-xs text-muted mt-8">
                Your data. Your infrastructure.
            </p>
        </div>
        <UDashboardGroup
            v-else
            unit="px"
            :persistent="false"
            class="perysee-shell"
        >
            <UDashboardSidebar
                :default-size="232"
                :min-size="232"
                :max-size="232"
                class="perysee-sidebar"
                :ui="{
                    root: 'border-e border-default bg-default',
                    header: 'h-16 px-6 border-0',
                    body: 'px-4 py-3 gap-0',
                    footer: 'border-t border-default px-4 py-3',
                    content: 'max-w-xs',
                }"
                aria-label="Main navigation"
            >
                <template #header><BrandLogo /></template>
                <div class="perysee-nav">
                    <button
                        type="button"
                        class="sidebar-search"
                        aria-haspopup="dialog"
                        aria-keyshortcuts="Meta+K Control+K"
                        @click="paletteOpen = true"
                    >
                        <UIcon
                            name="i-tabler-search"
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>Search</span>
                        <span class="ml-auto inline-flex gap-0.5"
                            ><UKbd value="meta" /><UKbd value="K"
                        /></span>
                    </button>
                    <p
                        v-if="!hasSite"
                        class="text-xs font-medium text-muted px-2 mb-2"
                    >
                        Workspace
                    </p>
                    <UNavigationMenu
                        :items="nav"
                        orientation="vertical"
                        color="primary"
                        variant="pill"
                        :ui="{ link: 'py-1.5 text-sm' }"
                        class="sidebar-nav"
                    />
                    <section v-if="!hasSite" class="sidebar-sites">
                        <div
                            class="flex items-center justify-between px-2 mb-2"
                        >
                            <h2 class="text-xs font-medium text-muted">
                                Your sites
                            </h2>
                            <UButton
                                :to="p.links['sites.create']"
                                icon="i-tabler-plus"
                                size="xs"
                                color="neutral"
                                variant="ghost"
                                aria-label="Add site"
                            />
                        </div>
                        <UInput
                            v-if="p.navigation.length > 5"
                            v-model="siteSearch"
                            icon="i-tabler-search"
                            placeholder="Find a site"
                            aria-label="Find a site"
                            size="sm"
                            class="w-full mb-2"
                            @keydown.enter="openFirstSite"
                        /><UNavigationMenu
                            :items="quickSites"
                            orientation="vertical"
                            :ui="{ link: 'py-1.5 text-sm' }"
                        />
                        <p
                            v-if="!quickSites.length"
                            class="text-xs text-muted px-2"
                        >
                            No matching sites.
                        </p>
                        <UButton
                            v-if="p.navigation.length > 8"
                            :to="p.links['sites.index']"
                            label="View all sites"
                            size="xs"
                            variant="link"
                            color="neutral"
                        />
                    </section>
                    <template v-if="hasSite && siteModules.length">
                        <p class="sidebar-label">Workspace</p>
                        <UNavigationMenu
                            :items="siteModules"
                            orientation="vertical"
                            :ui="{ link: 'py-1.5 text-sm' }"
                        />
                    </template>
                    <p class="sidebar-label">
                        {{ hasSite ? "Configuration" : "Manage" }}
                    </p>
                    <UNavigationMenu
                        :items="configNav"
                        orientation="vertical"
                        :ui="{ link: 'py-1.5 text-sm' }"
                    />
                </div>
                <template #footer>
                    <UDropdownMenu
                        :items="accountMenu"
                        :content="{ side: 'top', align: 'start' }"
                        class="w-full"
                    >
                        <UButton
                            color="neutral"
                            variant="ghost"
                            icon="i-tabler-user"
                            trailing-icon="i-tabler-selector"
                            :label="p.auth.name"
                            aria-label="Account menu"
                            class="w-full"
                            :ui="{ label: 'truncate', trailingIcon: 'ms-auto' }"
                        />
                    </UDropdownMenu>
                </template>
            </UDashboardSidebar>
            <UDashboardPanel
                id="workspace"
                class="perysee-workspace"
                :ui="{ root: 'min-h-0 h-full lg:not-last:border-e-0' }"
            >
                <UDashboardNavbar
                        class="perysee-topbar"
                        :toggle="{ color: 'neutral', variant: 'ghost', 'aria-label': 'Open navigation' }"
                    >
                        <template #left
                            ><div class="flex min-w-0 items-center gap-3">
                                <span class="hidden sm:block text-xs text-muted"
                            >{{ p.branding.name }}</span
                        ><span class="hidden sm:block text-dimmed text-xs"
                            >/</span
                        ><UDropdownMenu v-if="hasSite" :items="siteMenu"
                            ><UButton
                                color="neutral"
                                variant="ghost"
                                :avatar="siteAvatar(p.siteLinks.favicon, p.website.name)"
                                trailing-icon="i-tabler-chevron-down"
                                :label="p.website.domain"
                                class="max-w-52 sm:max-w-sm truncate" /></UDropdownMenu
                        ><span v-else class="text-sm text-muted">{{
                            title
                        }}</span>

                            </div></template
                        >
                        <template #right
                            ><div class="flex items-center gap-2">
                        <ColorModeButton />
                        <UButton
                            color="neutral"
                            variant="ghost"
                            icon="i-tabler-brand-github"
                            :to="p.repositoryUrl"
                            target="_blank"
                            rel="noopener"
                            aria-label="GitHub repository"
                        />
                        <UButton
                            color="neutral"
                            variant="ghost"
                            icon="i-tabler-book-2"
                            :to="p.docsUrl"
                            target="_blank"
                            rel="noopener"
                            aria-label="Documentation"
                        />
                            </div></template
                        >
                    </UDashboardNavbar>
                <main
                    id="main"
                    scroll-region
                    class="perysee-scroll"
                    :class="{ 'is-navigating': busy }"
                    :aria-busy="busy"
                >
                    <div class="perysee-content"><slot /></div>
                </main>
            </UDashboardPanel>
        </UDashboardGroup>
    </UApp>
</template>
