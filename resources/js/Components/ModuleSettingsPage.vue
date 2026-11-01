<script>
export default { inheritAttrs: false };
</script>
<script setup>
import { computed, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { moduleHost } from "../module-runtime.js";
import ModuleSlotBoundary from "./ModuleSlotBoundary.vue";
const props = defineProps(["module", "scope", "siteChoices", "links"]);
const mode = ref(props.scope.all ? "all" : "sites");
const selected = ref([...props.scope.site_ids]);
const saving = ref(false);
const moduleSettings = computed(() => moduleHost.settingsPages[props.module.id]);
const items = [
    { value: "all", label: "Whole instance", description: "The module is active on every site, now and in the future." },
    { value: "sites", label: "Selected sites", description: "The module is active only on the sites you choose." },
];
const siteItems = computed(() => props.siteChoices.map((site) => ({ value: site.id, label: site.name, description: site.domain })));
const save = () => {
    saving.value = true;
    router.post(props.links.save, { scope: mode.value, sites: mode.value === "sites" ? selected.value : [] }, { preserveScroll: true, onFinish: () => (saving.value = false) });
};
</script>
<template>
    <div class="page-heading">
        <div>
            <Link :href="links.back" class="eyebrow">&larr; Modules</Link>
            <h1>{{ module.name }}</h1>
            <p class="text-sm text-muted">
                v{{ module.version }}<span v-if="module.description"> · {{ module.description }}</span>
            </p>
        </div>
        <UBadge :label="module.enabled ? 'Enabled' : 'Disabled'" :color="module.enabled ? 'success' : 'neutral'" variant="subtle" />
    </div>
    <UAlert
        v-if="module.failure"
        color="error"
        variant="soft"
        icon="i-tabler-alert-triangle"
        title="This module could not start"
        :description="`${module.failure}. Perysee keeps running without it.`"
        class="mb-6"
    />
    <UCard>
        <h2 class="text-base font-medium">Availability</h2>
        <p class="text-sm text-muted mt-1">Choose where this module is active.</p>
        <form class="mt-5 space-y-5" @submit.prevent="save">
            <URadioGroup v-model="mode" :items="items" />
            <div v-if="mode === 'sites'" class="pl-6">
                <UCheckboxGroup v-if="siteItems.length" v-model="selected" :items="siteItems" />
                <p v-else class="text-sm text-muted">You do not have access to any site yet.</p>
                <p v-if="scope.hidden" class="text-xs text-dimmed mt-3">
                    {{ scope.hidden }} other selected {{ scope.hidden === 1 ? "site" : "sites" }} you cannot access
                    {{ scope.hidden === 1 ? "is" : "are" }} kept as is.
                </p>
                <p v-if="!selected.length && !scope.hidden" class="text-xs text-warning mt-3">
                    No site selected: the module will be active on none.
                </p>
            </div>
            <UButton type="submit" label="Save availability" :loading="saving" />
        </form>
    </UCard>
    <UAlert
        v-if="!module.enabled"
        color="neutral"
        variant="soft"
        icon="i-tabler-info-circle"
        :title="module.installed ? 'This module is disabled' : 'This module is not installed yet'"
        :description="module.installed ? 'Enable it from the Modules page to use its settings.' : 'Enabling it for the first time installs it, including its database changes.'"
        class="mt-6"
    />
    <ModuleSlotBoundary v-else-if="moduleSettings">
        <component :is="moduleSettings" v-bind="$attrs" />
    </ModuleSlotBoundary>
</template>
