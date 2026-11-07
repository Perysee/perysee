<script setup>
import { computed, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
const props = defineProps(["website", "siteLinks", "apiTokens", "scopeOptions", "rateLimits", "apiBase", "created"]);
const names = {
    overview: "Overview and time series",
    pages: "Pages",
    sources: "Sources",
    campaigns: "Campaigns",
    devices: "Devices & browsers",
    countries: "Countries",
    events: "Events",
    goals: "Goals",
    funnels: "Funnels",
};
const form = useForm({ label: "", scopes: ["overview"], rate_per_minute: props.rateLimits.default, expires_on: "" });
const copied = ref(false);
const copyFailed = ref(false);
const colors = { active: "success", expired: "warning", revoked: "neutral" };
const date = (value) => (value ? new Intl.DateTimeFormat("en", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "Never");
const base = computed(() => typeof window === "undefined" ? props.apiBase : new URL(props.apiBase, window.location.origin).href);
const example = computed(() => (props.created ? `curl -H "Authorization: Bearer ${props.created.token}" "${base.value}/overview?period=30d"` : ""));
function create() {
    form.post(props.siteLinks["api-tokens"], { preserveScroll: true, onSuccess: () => form.reset("label", "expires_on") });
}
function revoke(token) {
    if (window.confirm("Revoke this token? Every integration that uses it stops working immediately.")) {
        router.delete(token.revoke_url, { preserveScroll: true });
    }
}
async function copy() {
    try {
        await navigator.clipboard.writeText(props.created.token);
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
            <h1>API access</h1>
            <p class="text-sm text-muted">Read-only tokens for this site. Use them from a server, never from a web page.</p>
        </div>
    </div>
    <UAlert v-if="created" class="mb-5" color="success" variant="soft" icon="i-tabler-key" title="Token created">
        <template #description>
            <p class="text-sm">Copy it now. For your security it cannot be shown again.</p>
            <pre class="code-block select-all mt-3 wrap-code" tabindex="0" aria-label="API token"><code>{{ created.token }}</code></pre>
            <UButton class="mt-3" :icon="copied ? 'i-tabler-check' : 'i-tabler-copy'" :label="copied ? 'Copied' : 'Copy token'" color="neutral" variant="outline" size="sm" @click="copy" />
            <p v-if="copyFailed" role="alert" class="mt-2 text-sm text-warning">Copy unavailable. Select the token above and copy it manually.</p>
            <p class="mt-4 text-sm">Try it from a terminal:</p>
            <pre class="code-block mt-2 wrap-code" tabindex="0" aria-label="Example request"><code>{{ example }}</code></pre>
        </template>
    </UAlert>
    <UCard class="mb-5">
        <template #header><h2 class="text-sm font-medium">New token</h2></template>
        <form class="space-y-4" @submit.prevent="create">
            <UFormField label="Label" hint="Optional, for you only" :error="form.errors.label">
                <UInput v-model="form.label" maxlength="120" class="w-full" />
            </UFormField>
            <UFormField label="Reports this token can read" :error="form.errors.scopes || form.errors['scopes.0']">
                <div class="flex flex-wrap gap-x-5 gap-y-2">
                    <label v-for="scope in scopeOptions" :key="scope" class="flex items-center gap-2 text-sm">
                        <input v-model="form.scopes" type="checkbox" :value="scope" />{{ names[scope] || scope }}
                    </label>
                </div>
            </UFormField>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <UFormField label="Requests per minute" :hint="`${rateLimits.min} to ${rateLimits.max}`" :error="form.errors.rate_per_minute">
                    <UInput v-model="form.rate_per_minute" type="number" :min="rateLimits.min" :max="rateLimits.max" class="w-full" />
                </UFormField>
                <UFormField label="Expires after" hint="Optional" :error="form.errors.expires_on">
                    <UInput v-model="form.expires_on" type="date" class="w-full" />
                </UFormField>
            </div>
            <UButton type="submit" label="Create token" icon="i-tabler-plus" :loading="form.processing" />
        </form>
    </UCard>
    <UCard>
        <template #header><h2 class="text-sm font-medium">Tokens</h2></template>
        <p v-if="!apiTokens.length" class="text-sm text-muted">No token yet.</p>
        <ul v-else class="divide-y divide-default">
            <li v-for="token in apiTokens" :key="token.id" class="flex flex-wrap items-start justify-between gap-3 py-4">
                <div class="min-w-0 space-y-1">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                        <span class="break-words">{{ token.label || "Untitled token" }}</span>
                        <UBadge :color="colors[token.status]" variant="soft" :label="token.status" />
                    </p>
                    <p class="text-xs text-muted">Ends with <code>{{ token.hint }}</code> · {{ token.rate_per_minute }} requests a minute · {{ token.scopes.map((s) => names[s] || s).join(", ") }}</p>
                    <p class="text-xs text-muted">
                        Created {{ date(token.created_at) }}<template v-if="token.created_by"> by {{ token.created_by }}</template> · Expires {{ date(token.expires_at) }} · Last used {{ date(token.last_used_at) }}
                    </p>
                </div>
                <div v-if="token.status !== 'revoked'" class="flex flex-wrap items-center gap-2">
                    <UButton label="Revoke" icon="i-tabler-trash" color="error" variant="ghost" size="sm" @click="revoke(token)" />
                </div>
            </li>
        </ul>
    </UCard>
</template>
<style scoped>
/* .code-block keeps long lines on one line and scrolls; a token and a command must wrap instead. */
.wrap-code {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
</style>
