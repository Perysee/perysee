<script>
import WorkspaceLayout from "../Components/WorkspaceLayout.vue";
export default { layout: WorkspaceLayout };
</script>
<script setup>
import { computed, defineAsyncComponent } from "vue";
import { usePage } from "@inertiajs/vue3";
const ReportPage = defineAsyncComponent(
    () => import("../Components/ReportPage.vue"),
);
const SiteDirectory = defineAsyncComponent(
    () => import("../Components/SiteDirectory.vue"),
);
const DashboardPage = defineAsyncComponent(
    () => import("../Components/DashboardPage.vue"),
);
const SiteForm = defineAsyncComponent(
    () => import("../Components/SiteForm.vue"),
);
const HealthPage = defineAsyncComponent(
    () => import("../Components/HealthPage.vue"),
);
const AccountPage = defineAsyncComponent(
    () => import("../Components/AccountPage.vue"),
);
const InstallerPage = defineAsyncComponent(
    () => import("../Components/InstallerPage.vue"),
);
const TrackingPage = defineAsyncComponent(
    () => import("../Components/TrackingPage.vue"),
);
const page = usePage();
const p = computed(() => page.props);
</script>
<template>
    <template v-if="!p.auth || p.screen === 'install'">
        <InstallerPage v-if="p.screen === 'install'" v-bind="p" />
        <AccountPage v-else v-bind="p" />
    </template>
    <template v-else>
        <ReportPage
            v-if="p.screen === 'sites.show'"
            :key="p.website.id + ':' + (p.section || 'overview')"
            v-bind="p"
        />
        <SiteDirectory v-else-if="p.screen === 'sites.index'" v-bind="p" />
        <DashboardPage v-else-if="p.screen === 'dashboard'" v-bind="p" />
        <SiteForm
            v-else-if="p.screen === 'sites.form'"
            :key="p.website?.id || 'new'"
            v-bind="p"
        />
        <TrackingPage v-else-if="p.screen === 'sites.tracking'" v-bind="p" />
        <HealthPage v-else-if="p.screen === 'health'" v-bind="p" />
        <AccountPage v-else-if="p.screen === 'auth.password'" v-bind="p" />
        <template v-else-if="p.screen === 'settings'"
            ><div class="page-heading">
                <div>
                    <p class="eyebrow">Administration</p>
                    <h1>Instance settings</h1>
                    <p class="text-sm text-muted">Current configuration</p>
                </div>
            </div>
            <UCard
                ><dl class="divide-y divide-default">
                    <div
                        v-for="(value, label) in p.settings"
                        :key="label"
                        class="py-4 flex justify-between gap-6 text-sm"
                    >
                        <dt class="text-muted">{{ label }}</dt>
                        <dd>{{ value }}</dd>
                    </div>
                </dl></UCard
            ></template
        >
        <UEmpty
            v-else
            :title="p.title || 'Coming soon'"
            :description="p.message"
            icon="i-tabler-clock"
        />
    </template>
</template>
