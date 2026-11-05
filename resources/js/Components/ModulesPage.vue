<script setup>
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
const props = defineProps(["installed", "invalid", "path", "installer", "modulesEnabled", "moduleApi"]);
const busy = ref("");
const installing = ref(false);
const updating = ref(null);
const sending = ref(false);
const bundle = ref(null);
const startUpdate = (module) => {
    updating.value = module;
    bundle.value = null;
    installing.value = true;
};
const closeUpload = (open) => {
    if (!open) {
        updating.value = null;
    }
};
const send = () => {
    if (!bundle.value) return;
    sending.value = true;
    const target = updating.value ? updating.value.links.update : props.installer.link;
    router.post(target, { bundle: bundle.value }, {
        forceFormData: true,
        onSuccess: () => { installing.value = false; updating.value = null; bundle.value = null; },
        onFinish: () => (sending.value = false),
    });
};
const confirming = ref(null);
const accepted = ref(false);
const change = (module, action, data = {}) => {
    busy.value = module.id;
    router.post(module.links[action], data, { preserveScroll: true, onFinish: () => (busy.value = "") });
};
const enable = (module) => {
    if (module.stability === "experimental") {
        accepted.value = false;
        confirming.value = module;
        return;
    }
    change(module, "enable");
};
const confirmEnable = () => {
    const module = confirming.value;
    confirming.value = null;
    change(module, "enable", { accept_risk: true });
};
const stabilityOf = (module) => {
    if (module.stability === "experimental") return { label: "Experimental", color: "error" };
    if (module.stability === "beta") return { label: "Beta", color: "warning" };
    return { label: "Stable", color: "neutral" };
};
const stabilityNote = (module) => {
    if (module.stability === "experimental") return "Experimental: it may break or lose data, and it can change without notice.";
    if (module.stability === "beta") return "Beta: it is still being tested and may change between versions.";
    return "";
};
const stateOf = (module) => {
    if (module.quarantine) return { label: "In quarantine", color: "error" };
    if (module.failure) return { label: "Failed to load", color: "error" };
    if (module.api !== props.moduleApi) return { label: "Other module API", color: "warning" };
    if (!module.compatible) return { label: "Needs a newer Perysee", color: "warning" };
    return module.enabled ? { label: "Enabled", color: "success" } : { label: "Disabled", color: "neutral" };
};
const blockedReason = (module) => {
    if (module.enabled && module.dependents.length) return `Required by ${module.dependents.join(", ")}`;
    if (!module.enabled && module.api !== props.moduleApi) return `Written for module API ${module.api}; this Perysee supports ${props.moduleApi}`;
    if (!module.enabled && !module.compatible) return `Requires Perysee ${module.core} or later`;
    if (!module.enabled && module.missing_dependencies.length) return `Enable ${module.missing_dependencies.join(", ")} first`;
    return "";
};
const enabledCount = computed(() => props.installed.filter((module) => module.enabled).length);
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">Administration</p>
            <h1>Modules</h1>
            <p class="text-sm text-muted">
                {{ enabledCount }} of {{ installed.length }} installed modules enabled
            </p>
        </div>
        <div class="flex flex-col items-end gap-1">
            <UButton
                label="Install a module"
                icon="i-tabler-upload"
                :disabled="!installer.available"
                @click="installing = true"
            />
            <p v-if="!installer.available" class="text-xs text-muted max-w-64 text-right">{{ installer.reason }}</p>
        </div>
    </div>
    <UAlert
        v-if="!modulesEnabled"
        color="warning"
        variant="soft"
        icon="i-tabler-puzzle-off"
        title="Modules are switched off"
        description="PERYSEE_MODULES is set to false in the environment file, so no module is loaded and none can run. Enabled modules keep their state. Remove the setting and reload to use them again."
        class="mb-6"
    />
    <UModal
        v-model:open="installing"
        :title="updating ? `Update ${updating.name}` : 'Install a module'"
        :description="updating ? `Upload the ZIP archive of a newer version of ${updating.name}. The current version is kept as a backup; enabling the module applies the database changes of the new one.` : 'Upload the ZIP archive of a module. It is unpacked but not run: you enable it afterwards.'"
        @update:open="closeUpload"
    >
        <template #body>
            <UAlert
                color="warning"
                variant="soft"
                icon="i-tabler-alert-triangle"
                title="Only install code you trust"
                description="A module is PHP code that runs with the same access as Perysee, including your database and files. Archives are not signed yet."
                class="mb-5"
            />
            <input
                type="file"
                accept=".zip,application/zip"
                aria-label="Module archive"
                class="block w-full text-sm file:mr-4 file:border file:border-default file:bg-default file:px-3 file:py-1.5 file:text-sm"
                @change="bundle = $event.target.files?.[0] ?? null"
            />
            <p class="text-xs text-muted mt-2">ZIP file, {{ installer.maxMb }} MB at most, with a module.json at its top.</p>
        </template>
        <template #footer>
            <div class="flex justify-end gap-3 w-full">
                <UButton label="Cancel" color="neutral" variant="ghost" @click="installing = false" />
                <UButton :label="updating ? 'Update' : 'Install'" icon="i-tabler-upload" :disabled="!bundle" :loading="sending" @click="send" />
            </div>
        </template>
    </UModal>
    <UModal
        :open="confirming !== null"
        title="Enable an experimental module"
        :description="confirming ? `${confirming.name} is experimental.` : ''"
        @update:open="confirming = null"
    >
        <template #body>
            <UAlert
                color="error"
                variant="soft"
                icon="i-tabler-alert-triangle"
                title="It may break or lose data"
                description="An experimental module can fail, change without notice or damage the data it stores. Back up your database before you enable it."
                class="mb-5"
            />
            <UCheckbox v-model="accepted" label="I have a backup and I accept the risk" />
        </template>
        <template #footer>
            <div class="flex justify-end gap-3 w-full">
                <UButton label="Cancel" color="neutral" variant="ghost" @click="confirming = null" />
                <UButton label="Enable" color="error" :disabled="!accepted" @click="confirmEnable" />
            </div>
        </template>
    </UModal>
    <UEmpty
        v-if="!installed.length && !invalid.length"
        title="No module installed"
        description="Modules add features to Perysee, such as white label. Install one in the modules folder, then enable it here."
        icon="i-tabler-puzzle"
    />
    <UCard v-else-if="installed.length">
        <ul class="divide-y divide-default">
            <li v-for="module in installed" :key="module.id" class="py-5 first:pt-0 last:pb-0 flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1 basis-72">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-medium">{{ module.name }}</h2>
                        <span class="text-xs text-muted">v{{ module.version }}</span>
                        <UBadge v-bind="stateOf(module)" variant="subtle" size="sm" />
                        <UBadge v-bind="stabilityOf(module)" variant="outline" size="sm" />
                        <UBadge
                            v-if="module.enabled && !module.scope.all"
                            :label="module.scope.count === 1 ? '1 site' : `${module.scope.count} sites`"
                            color="neutral"
                            variant="outline"
                            size="sm"
                        />
                    </div>
                    <p v-if="module.description" class="text-sm text-muted mt-1">{{ module.description }}</p>
                    <p v-if="stabilityNote(module)" class="text-xs text-muted mt-1">{{ stabilityNote(module) }}</p>
                    <p v-if="module.requires.length" class="text-xs text-dimmed mt-1">Requires {{ module.requires.join(", ") }}</p>
                    <p v-if="!module.installed" class="text-xs text-dimmed mt-1">Enabling it for the first time installs it, including its database changes.</p>
                    <UAlert
                        v-if="module.quarantine"
                        color="error"
                        variant="soft"
                        icon="i-tabler-shield-off"
                        title="Disabled automatically after repeated failures"
                        :description="`${module.quarantine.reason}. Its data is kept. Enable it again once it is fixed or replaced.`"
                        class="mt-3"
                    />
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
                        v-if="!module.enabled && installer.available"
                        label="Update"
                        color="neutral"
                        variant="ghost"
                        icon="i-tabler-upload"
                        @click="startUpdate(module)"
                    />
                    <UButton
                        v-if="!module.enabled"
                        label="Enable"
                        :loading="busy === module.id"
                        :disabled="!module.compatible || module.missing_dependencies.length > 0"
                        @click="enable(module)"
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
