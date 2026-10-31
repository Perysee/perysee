<script setup>
import { computed, ref, watch, onBeforeUnmount } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { useToast } from "@nuxt/ui/composables";
import { queryUrl, reportFilters } from "../interface-query.mjs";
import { createBusyTracker, networkErrorToast } from "../navigation-feedback.mjs";
import BrandLogo from "./BrandLogo.vue";
import ColorModeButton from "./ColorModeButton.vue";
const page = usePage(),
    mobileOpen = ref(false),
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
            funnels: "Funnels",
            "funnel.report": p.value.funnel?.name || "Funnel",
            journeys: "Journeys",
            dashboard: "Dashboard",
            health: "Instance health",
            settings: "Instance settings",
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
const workspaceNav = computed(() => [
    {
        label: "Dashboard",
        icon: "i-tabler-layout-dashboard",
        to: p.value.links.dashboard,
        active: p.value.screen === "dashboard",
    },
    {
        label: "All sites",
        icon: "i-tabler-world",
        to: p.value.links["sites.index"],
        active: p.value.screen === "sites.index",
    },
]);
const quickSites = computed(() =>
    p.value.navigation
        .filter((site) =>
            (site.name + " " + site.domain)
                .toLowerCase()
                .includes(siteSearch.value.toLowerCase()),
        )
        .slice(0, 8)
        .map((site) => ({
            label: site.name,
            to: site.url,
            avatar: { src: site.favicon_url, alt: "" },
            active: site.id === p.value.website?.id,
        })),
);
const sections = [
    ["overview", "Overview", "i-tabler-chart-line"],
    ["pages", "Pages", "i-tabler-file-text"],
    ["sources", "Sources", "i-tabler-arrow-up-right"],
    ["campaigns", "Campaigns", "i-tabler-speakerphone"],
    ["devices", "Devices", "i-tabler-device-desktop"],
    ["countries", "Countries", "i-tabler-world"],
    ["events", "Events", "i-tabler-bolt"],
    ["sessions", "Sessions", "i-tabler-users"],
    ["funnels", "Funnels", "i-tabler-filter"],
    ["journeys", "Journeys", "i-tabler-route"],
    ["realtime", "Recent activity", "i-tabler-activity"],
];
const nav = computed(() => {
    if (hasSite.value) {
        const values = {...reportFilters(page.url)};
        delete values.value;
        delete values.dimension;
        const dates = new URLSearchParams();
        const params = new URL(page.url, location.origin).searchParams;
        for (const key of ["from", "to", "period"])
            if (params.has(key)) dates.set(key, params.get(key));
        return sections.map(([key, label, icon]) => ({
            label,
            icon,
            to:
                queryUrl(p.value.siteLinks[key === "overview" ? "show" : key], key === "realtime" ? {} : {...values, ...Object.fromEntries(dates)}),
            active: key === "funnels" ? ["funnels","funnel.report"].includes(p.value.screen) : key === "journeys" ? p.value.screen === "journeys" : p.value.screen === "sites.show" && (p.value.section || "overview") === key,
        }));
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
        avatar: { src: site.favicon_url, alt: "" },
    })),
    [
        {
            label: "All sites",
            to: p.value.links["sites.index"],
            icon: "i-tabler-arrow-left",
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
const toast = useToast(),
    busy = ref(false),
    tracker = createBusyTracker((value) => (busy.value = value));
watch(
    () => p.value.flash?.status,
    (status) => {
        if (status && p.value.auth)
            toast.add({ title: status, color: "success", icon: "i-tabler-circle-check" });
    },
    { immediate: true },
);
const stops = [
    router.on("navigate", () => {
        mobileOpen.value = false;
    }),
    router.on("start", tracker.start),
    router.on("finish", tracker.finish),
    router.on("exception", () => toast.add(networkErrorToast())),
    router.on("invalid", () => toast.add(networkErrorToast())),
];
onBeforeUnmount(() => stops.forEach((stop) => stop()));
</script>
<template>
    <UApp>
        <Head :title="title" />
        <a href="#main" class="skip-link">Skip to content</a>
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
        <div v-else class="perysee-shell">
            <aside class="perysee-sidebar" aria-label="Main navigation">
                <div class="h-18 flex items-center px-6"><BrandLogo /></div>
                <div class="flex-1 overflow-y-auto px-4 py-3">
                    <UNavigationMenu
                        v-if="hasSite"
                        :items="workspaceNav"
                        orientation="vertical"
                        :ui="{ link: 'py-2 text-[13px]' }"
                        class="mb-5"
                    />
                    <div v-if="hasSite" class="sidebar-site-context">
                        <UAvatar :src="p.siteLinks.favicon" alt="" size="sm" />
                        <div class="min-w-0">
                            <p class="text-sm font-medium truncate">
                                {{ p.website.name }}
                            </p>
                            <p class="text-xs text-muted truncate">
                                {{ p.website.domain }}
                            </p>
                        </div>
                    </div>
                    <p class="text-xs font-medium text-muted px-2 mb-3">
                        {{ hasSite ? "Traffic" : "Workspace" }}
                    </p>
                    <UNavigationMenu
                        :items="nav"
                        orientation="vertical"
                        color="primary"
                        variant="pill"
                        :ui="{ link: 'py-2 text-[13px]' }"
                    />
                    <section v-if="!hasSite" class="sidebar-sites">
                        <div
                            class="flex items-center justify-between px-2 mb-3"
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
                            class="w-full mb-3"
                        /><UNavigationMenu
                            :items="quickSites"
                            orientation="vertical"
                            :ui="{ link: 'py-2 text-[13px]' }"
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
                    <p class="text-xs font-medium text-muted px-2 mt-5 mb-2">
                        {{ hasSite ? "Configuration" : "Manage" }}
                    </p>
                    <UNavigationMenu
                        :items="configNav"
                        orientation="vertical"
                        :ui="{ link: 'py-2 text-[13px]' }"
                    />
                </div>
                <div
                    class="border-t border-default px-6 py-4 flex gap-3 items-center text-xs text-muted"
                >
                    <UIcon
                        name="i-tabler-shield-check"
                        class="size-5 shrink-0"
                    /><span>Your data.<br />Your infrastructure.</span>
                </div>
            </aside>
            <div class="perysee-workspace">
                <header class="perysee-topbar">
                    <div class="flex min-w-0 items-center gap-3">
                        <USlideover
                            v-model:open="mobileOpen"
                            side="left"
                            title="Navigation"
                            class="lg:hidden"
                            :ui="{ content: 'max-w-xs' }"
                            ><UButton
                                icon="i-tabler-menu-2"
                                color="neutral"
                                variant="ghost"
                                aria-label="Open navigation" /><template #body
                                ><BrandLogo />
                                <div class="mt-8">
                                    <UNavigationMenu
                                        v-if="hasSite"
                                        :items="workspaceNav"
                                        orientation="vertical"
                                        class="mb-5"
                                    />
                                    <UNavigationMenu
                                        :items="nav"
                                        orientation="vertical"
                                    /><UNavigationMenu
                                        v-if="!hasSite"
                                        :items="quickSites"
                                        orientation="vertical"
                                        class="mt-5"
                                    /><USeparator
                                        class="my-5"
                                    /><UNavigationMenu
                                        :items="configNav"
                                        orientation="vertical"
                                    /></div></template></USlideover
                        ><span class="hidden sm:block text-xs text-muted"
                            >Perysee</span
                        ><span class="hidden sm:block text-dimmed text-xs"
                            >/</span
                        ><UDropdownMenu v-if="hasSite" :items="siteMenu"
                            ><UButton
                                color="neutral"
                                variant="ghost"
                                :avatar="{
                                    src: p.siteLinks.favicon,
                                    alt: p.website.name,
                                }"
                                trailing-icon="i-tabler-chevron-down"
                                :label="p.website.domain"
                                class="max-w-52 sm:max-w-sm truncate" /></UDropdownMenu
                        ><span v-else class="text-sm text-muted">{{
                            title
                        }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <ColorModeButton />
                        <UDropdownMenu :items="accountMenu"
                            ><UButton
                                color="neutral"
                                variant="ghost"
                                icon="i-tabler-user"
                                trailing-icon="i-tabler-chevron-down"
                                :label="p.auth.name"
                                aria-label="Account menu"
                        /></UDropdownMenu>
                    </div>
                </header>
                <main
                    id="main"
                    class="perysee-content"
                    :class="{ 'is-navigating': busy }"
                    :aria-busy="busy"
                >
                    <slot />
                </main>
            </div>
        </div>
    </UApp>
</template>
