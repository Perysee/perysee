<script setup>
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
const props = defineProps(["modules", "invalid", "path"]);
const busy = ref("");
const change = (module, action) => {
    busy.value = module.id;
    router.post(module.links[action], {}, { preserveScroll: true, onFinish: () => (busy.value = "") });
};
const stateOf = (module) => {
    if (module.failure) return { label: "Failed to load", color: "error" };
    if (!module.compatible) return { label: "Needs a newer Perysee", color: "warning" };
    return module.enabled ? { label: "Enabled", color: "success" } : { label: "Disabled", color: "neutral" };
};
const blockedReason = (module) => {
    if (module.enabled && module.dependents.length) return `Required by ${module.dependents.join(", ")}`;
    if (!module.enabled && !module.compatible) return `Requires Perysee ${module.core} or later`;
    if (!module.enabled && module.missing_dependencies.length) return `Enable ${module.missing_dependencies.join(", ")} first`;
    return "";
};
const enabledCount = computed(() => props.modules.filter((module) => module.enabled).length);
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">Administration</p>
            <h1>Modules</h1>
            <p class="text-sm text-muted">
                {{ enabledCount }} of {{ modules.length }} installed modules enabled
            </p>
        </div>
    </div>
    <UEmpty
        v-if="!modules.length && !invalid.length"
        title="No module installed"
        description="Modules add features to Perysee, such as white label. Install one in the modules folder, then enable it here."
        icon="i-tabler-puzzle"
    />
    <UCard v-else-if="modules.length">
        <ul class="divide-y divide-default">
            <li v-for="module in modules" :key="module.id" class="py-5 first:pt-0 last:pb-0 flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1 basis-72">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-medium">{{ module.name }}</h2>
                        <span class="text-xs text-muted">v{{ module.version }}</span>
                        <UBadge v-bind="stateOf(module)" variant="subtle" size="sm" />
                        <UBadge
                            v-if="module.enabled && !module.scope.all"
                            :label="module.scope.count === 1 ? '1 site' : `${module.scope.count} sites`"
                            color="neutral"
                            variant="outline"
                            size="sm"
                        />
                    </div>
                    <p v-if="module.description" class="text-sm text-muted mt-1">{{ module.description }}</p>
                    <p v-if="module.requires.length" class="text-xs text-dimmed mt-1">Requires {{ module.requires.join(", ") }}</p>
                    <p v-if="!module.installed" class="text-xs text-dimmed mt-1">Enabling it for the first time installs it, including its database changes.</p>
                    <UAlert
                        v-if="module.failure"
                        color="error"
                        variant="soft"
                        icon="i-tabler-alert-triangle"
                        title="This module could not start"
                        :description="`${module.failure}. Perysee keeps running without it.`"
                        class="mt-3"
                    />
                </div>
                <div class="flex flex-col items-end gap-1">
                    <div class="flex gap-2">
                    <UButton :to="module.links.settings" label="Settings" color="neutral" variant="ghost" icon="i-tabler-settings" />
                    <UButton
                        v-if="module.enabled"
                        label="Disable"
                        color="neutral"
                        variant="outline"
                        :loading="busy === module.id"
                        :disabled="module.dependents.length > 0"
                        @click="change(module, 'disable')"
                    />
                    <UButton
                        v-else
                        label="Enable"
                        :loading="busy === module.id"
                        :disabled="!module.compatible || module.missing_dependencies.length > 0"
                        @click="change(module, 'enable')"
                    />
                    </div>
                    <p v-if="blockedReason(module)" class="text-xs text-muted">{{ blockedReason(module) }}</p>
                </div>
            </li>
        </ul>
    </UCard>
    <UAlert
        v-if="invalid.length"
        color="warning"
        variant="soft"
        icon="i-tabler-alert-triangle"
        title="Some folders were ignored"
        class="mt-6"
    >
        <template #description>
            <ul class="text-sm space-y-1 mt-1">
                <li v-for="item in invalid" :key="item.directory">
                    <code>{{ item.directory }}</code>: {{ item.reason }}
                </li>
            </ul>
        </template>
    </UAlert>
    <p class="text-xs text-dimmed mt-6">Modules folder: <code>{{ path }}</code></p>
</template>
