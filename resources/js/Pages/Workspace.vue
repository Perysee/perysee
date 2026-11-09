<script>
import WorkspaceLayout from "../Components/WorkspaceLayout.vue";
export default { layout: WorkspaceLayout };
</script>
<script setup>
import { computed, defineAsyncComponent, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { moduleHost } from "../module-runtime.js";
import { accentCss, brandState } from "../branding.mjs";
import ModuleSlot from "../Components/ModuleSlot.vue";
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
const ConfigurationAuditPage = defineAsyncComponent(() => import("../Components/ConfigurationAuditPage.vue"));
const MailSettingsPage =defineAsyncComponent(() => import("../Components/MailSettingsPage.vue"));
const SecurityPage = defineAsyncComponent(() => import("../Components/SecurityPage.vue"));
const SecuritySettingsPage = defineAsyncComponent(() => import("../Components/SecuritySettingsPage.vue"));
const TwoFactorChallengePage = defineAsyncComponent(() => import("../Components/TwoFactorChallengePage.vue"));
const AccountPage = defineAsyncComponent(
    () => import("../Components/AccountPage.vue"),
);
const InstallerPage = defineAsyncComponent(
    () => import("../Components/InstallerPage.vue"),
);
const TrackingPage = defineAsyncComponent(
    () => import("../Components/TrackingPage.vue"),
);
const TrackingVerifyPage = defineAsyncComponent(() => import("../Components/TrackingVerifyPage.vue"));
const TeamPage = defineAsyncComponent(() => import("../Components/TeamPage.vue"));
const InvitationPage = defineAsyncComponent(() => import("../Components/InvitationPage.vue"));
const ModulesPage = defineAsyncComponent(
    () => import("../Components/ModulesPage.vue"),
);
const ModuleSettingsPage = defineAsyncComponent(
    () => import("../Components/ModuleSettingsPage.vue"),
);
const SharingPage = defineAsyncComponent(() => import("../Components/SharingPage.vue"));
const ApiTokensPage = defineAsyncComponent(() => import("../Components/ApiTokensPage.vue"));
const FunnelsPage =defineAsyncComponent(() => import("../Components/FunnelsPage.vue"));
const JourneyPage = defineAsyncComponent(() => import("../Components/JourneyPage.vue"));
const GoalsPage = defineAsyncComponent(() => import("../Components/GoalsPage.vue"));
const GoalReport = defineAsyncComponent(() => import("../Components/GoalReport.vue"));
const EventPropertiesPage = defineAsyncComponent(() => import("../Components/EventPropertiesPage.vue"));
const page = usePage();
const p = computed(() => page.props);
const failedModuleFiles = ref([]);
watch(
    () => p.value.modules,
    async (modules) => {
        if (modules) {
            failedModuleFiles.value = await moduleHost.load(modules);
        }
    },
    { immediate: true },
);
watch(
    () => p.value.branding,
    (branding) => {
        brandState.name = branding?.name ?? "Perysee";
        let style = document.getElementById("perysee-accent");
        if (!style) {
            style = document.createElement("style");
            style.id = "perysee-accent";
            document.head.appendChild(style);
        }
        style.textContent = accentCss(branding?.accent);
    },
    { immediate: true },
);
const moduleScreen = computed(() => moduleHost.screens[p.value.screen]);
</script>
<template>
    <template v-if="!p.auth || p.screen === 'install'">
        <InstallerPage v-if="p.screen === 'install'" v-bind="p" />
        <InvitationPage v-else-if="p.screen === 'invitation'" v-bind="p" />
        <TwoFactorChallengePage v-else-if="p.screen === 'auth.two-factor'" v-bind="p" />
        <AccountPage v-else v-bind="p" />
    </template>
    <template v-else>
        <template v-if="p.screen === 'sites.show'"
            ><ReportPage
                :key="p.website.id + ':' + (p.section || 'overview')"
                v-bind="p"
            /><ModuleSlot name="site.report.after" v-bind="p"
        /></template>
        <SiteDirectory v-else-if="p.screen === 'sites.index'" v-bind="p" />
        <template v-else-if="p.screen === 'dashboard'"
            ><DashboardPage v-bind="p" /><ModuleSlot name="dashboard.after" v-bind="p"
        /></template>
        <SiteForm
            v-else-if="p.screen === 'sites.form'"
            :key="p.website?.id || 'new'"
            v-bind="p"
        />
        <TrackingPage v-else-if="p.screen === 'sites.tracking'" v-bind="p" />
        <TrackingVerifyPage v-else-if="p.screen === 'sites.verify'" v-bind="p" />
        <SharingPage v-else-if="p.screen === 'sites.sharing'" v-bind="p" />
        <ApiTokensPage v-else-if="p.screen === 'sites.api-tokens'" v-bind="p" />
        <TeamPage v-else-if="p.screen === 'sites.team'" v-bind="p" />
        <InvitationPage v-else-if="p.screen === 'invitation'" v-bind="p" />
        <FunnelsPage v-else-if="p.screen === 'funnels'" v-bind="p" />
        <JourneyPage v-else-if="['funnel.report','journeys'].includes(p.screen)" :key="p.funnel?.id || 'journeys'" v-bind="p" />
        <GoalsPage v-else-if="p.screen === 'goals'" v-bind="p" />
        <GoalReport v-else-if="p.screen === 'goal.report'" :key="p.goal?.id" v-bind="p" />
        <EventPropertiesPage v-else-if="p.screen === 'event.properties'" v-bind="p" />
        <HealthPage v-else-if="p.screen === 'health'" v-bind="p" />
        <ConfigurationAuditPage v-else-if="p.screen === 'health.audit'" v-bind="p" />
        <MailSettingsPage v-else-if="p.screen === 'settings.mail'" v-bind="p" />
        <ModulesPage v-else-if="p.screen === 'modules'" v-bind="p" />
        <ModuleSettingsPage v-else-if="p.screen === 'modules.settings'" v-bind="p" />
        <AccountPage v-else-if="p.screen === 'auth.password'" v-bind="p" />
        <SecurityPage v-else-if="p.screen === 'account.security'" v-bind="p" />
        <SecuritySettingsPage v-else-if="p.screen === 'settings.security'" v-bind="p" />
        <template v-else-if="p.screen === 'settings'"
            ><div class="page-heading">
                <div>
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
            ></template>
        <component
            :is="moduleScreen"
            v-else-if="p.screen.startsWith('module:') && moduleScreen"
            v-bind="p"
        />
        <UEmpty
            v-else-if="p.screen.startsWith('module:')"
            :title="failedModuleFiles.length ? 'This module could not be loaded' : 'Loading module'"
            :description="failedModuleFiles.length ? 'Its files are missing or unreachable. Disable the module from the Modules page, or check the instance logs.' : undefined"
            icon="i-tabler-puzzle"
        />
        <UEmpty
            v-else
            :title="p.title || 'Coming soon'"
            :description="p.message"
            icon="i-tabler-clock"
        />
    </template>
</template>
