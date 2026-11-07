<script setup>
import { computed, nextTick, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
const props = defineProps(["website", "siteLinks", "shareLinks", "sectionOptions", "created"]);
const names = { overview: "Overview", pages: "Pages", sources: "Sources", campaigns: "Campaigns", devices: "Devices & browsers", countries: "Countries", events: "Events" };
const form = useForm({ label: "", sections: ["overview"], password: "", expires_on: "" });
const copied = ref(false);
const copyFailed = ref(false);
const address = computed(() => (props.created ? new URL(props.created.url, window.location.origin).href : ""));
const colors = { active: "success", expired: "warning", revoked: "neutral" };
const date = (value) => (value ? new Intl.DateTimeFormat("en", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "Never");
function create() {
    form.post(props.siteLinks.sharing, { preserveScroll: true, onSuccess: () => form.reset("label", "password", "expires_on") });
}
const editing = ref(null);
const passwordForm = useForm({ password: "" });
function editPassword(link) {
    passwordForm.reset();
    passwordForm.clearErrors();
    editing.value = link.id;
}
function closePassword(link) {
    editing.value = null;
    nextTick(() => document.getElementById("password-button-" + link.id)?.focus());
}
function savePassword(link) {
    passwordForm.put(link.update_url, {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
            closePassword(link);
        },
    });
}
function removePassword(link) {
    if (window.confirm("Remove the password? Anyone holding the link will be able to open it without one.")) {
        router.put(link.update_url, { remove_password: true }, { preserveScroll: true });
    }
}
function revoke(link) {
    if (window.confirm("Revoke this link? Anyone holding it loses access immediately.")) {
        router.delete(link.revoke_url, { preserveScroll: true });
    }
}
async function copy() {
    try {
        await navigator.clipboard.writeText(address.value);
        copied.value = true;
        copyFailed.value = false;
    } catch {
        copied.value = false;
        copyFailed.value = true;
    }
}
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">{{ website.domain }}</p>
            <h1>Sharing</h1>
            <p class="text-sm text-muted">Read-only links to chosen reports, usable without an account.</p>
        </div>
    </div>
    <UAlert
        v-if="created"
        class="mb-5"
        color="success"
        variant="soft"
        icon="i-tabler-link"
        title="Link created"
    >
        <template #description>
            <p class="text-sm">Copy it now. For your security it cannot be shown again.</p>
            <pre class="code-block select-all mt-3 whitespace-pre-wrap break-all" tabindex="0" aria-label="Share link"><code>{{ address }}</code></pre>
            <UButton class="mt-3" :icon="copied ? 'i-tabler-check' : 'i-tabler-copy'" :label="copied ? 'Copied' : 'Copy link'" color="neutral" variant="outline" size="sm" @click="copy" />
            <p v-if="copyFailed" role="alert" class="mt-2 text-sm text-warning">Copy unavailable. Select the link above and copy it manually.</p>
        </template>
    </UAlert>
    <UCard class="mb-5">
        <template #header><h2 class="text-sm font-medium">New link</h2></template>
        <form class="space-y-4" @submit.prevent="create">
            <UFormField label="Label" hint="Optional, for you only" :error="form.errors.label">
                <UInput v-model="form.label" maxlength="120" class="w-full" />
            </UFormField>
            <UFormField label="Reports to share" :error="form.errors.sections || form.errors['sections.0']">
                <div class="flex flex-wrap gap-x-5 gap-y-2">
                    <label v-for="section in sectionOptions" :key="section" class="flex items-center gap-2 text-sm">
                        <input v-model="form.sections" type="checkbox" :value="section" />{{ names[section] || section }}
                    </label>
                </div>
            </UFormField>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <UFormField label="Password" hint="Optional, 8 to 128 characters" :error="form.errors.password">
                    <UInput v-model="form.password" type="password" autocomplete="new-password" maxlength="128" class="w-full" />
                </UFormField>
                <UFormField label="Expires after" hint="Optional" :error="form.errors.expires_on">
                    <UInput v-model="form.expires_on" type="date" class="w-full" />
                </UFormField>
            </div>
            <UButton type="submit" label="Create link" icon="i-tabler-plus" :loading="form.processing" />
        </form>
    </UCard>
    <UCard>
        <template #header><h2 class="text-sm font-medium">Links</h2></template>
        <p v-if="!shareLinks.length" class="text-sm text-muted">No link yet.</p>
        <ul v-else class="divide-y divide-default">
            <li v-for="link in shareLinks" :key="link.id" class="flex flex-wrap items-start justify-between gap-3 py-4">
                <div class="min-w-0 space-y-1">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                        <span class="break-words">{{ link.label || "Untitled link" }}</span>
                        <UBadge :color="colors[link.status]" variant="soft" :label="link.status" />
                        <UBadge v-if="link.protected" color="neutral" variant="outline" label="Password" icon="i-tabler-lock" />
                    </p>
                    <p class="text-xs text-muted">Starts with <code>{{ link.hint }}</code> · {{ link.sections.map((s) => names[s] || s).join(", ") }}</p>
                    <p class="text-xs text-muted">
                        Created {{ date(link.created_at) }} · Expires {{ date(link.expires_at) }} · Last used {{ date(link.last_used_at) }}
                    </p>
                </div>
                <div v-if="link.status !== 'revoked'" class="flex flex-wrap items-center gap-2">
                    <UButton :id="'password-button-' + link.id" :label="link.protected ? 'Change password' : 'Set password'" icon="i-tabler-lock" color="neutral" variant="ghost" size="sm" @click="editPassword(link)" />
                    <UButton v-if="link.protected" label="Remove password" icon="i-tabler-lock-open" color="neutral" variant="ghost" size="sm" @click="removePassword(link)" />
                    <UButton label="Revoke" icon="i-tabler-trash" color="error" variant="ghost" size="sm" @click="revoke(link)" />
                </div>
                <form v-if="editing === link.id" class="basis-full space-y-3" @submit.prevent="savePassword(link)" @keydown.esc.stop="closePassword(link)">
                    <UFormField label="New password" hint="8 to 128 characters. Anyone already signed in with this link is signed out." :error="passwordForm.errors.password">
                        <UInput v-model="passwordForm.password" type="password" autocomplete="new-password" maxlength="128" autofocus class="w-full max-w-sm" />
                    </UFormField>
                    <div class="flex flex-wrap gap-2">
                        <UButton type="submit" label="Save password" :loading="passwordForm.processing" size="sm" />
                        <UButton label="Cancel" color="neutral" variant="ghost" size="sm" @click="closePassword(link)" />
                    </div>
                </form>
            </li>
        </ul>
    </UCard>
</template>
