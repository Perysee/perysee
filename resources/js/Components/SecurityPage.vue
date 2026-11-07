<script setup>
import { computed, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import AccessEntries from "./AccessEntries.vue";
import { groupSecret, networkLabel, recoveryCodesText } from "../security.mjs";
const props = defineProps(["twoFactor", "recoveryCodes", "openSessions", "accessEntries", "securityUrls", "errors"]);
const start = useForm({ current_password: "" });
const confirm = useForm({ code: "" });
const manage = useForm({ current_password: "" });
const copied = ref(false);
const copiedKey = ref(false);
const others = computed(() => props.openSessions.filter((row) => !row.current).length);
const lowCodes = computed(() => props.twoFactor.remaining_codes <= 2);

const begin = () => start.post(props.securityUrls.start, { preserveScroll: true, onFinish: () => start.reset() });
const activate = () => confirm.post(props.securityUrls.confirm, { preserveScroll: true, onFinish: () => confirm.reset() });
const regenerate = () => manage.post(props.securityUrls.recovery, { preserveScroll: true, onFinish: () => manage.reset() });
const disable = () => {
    if (window.confirm("Turn off two-factor authentication? Your account will be protected by the password alone.")) {
        manage.delete(props.securityUrls.disable, { preserveScroll: true, onFinish: () => manage.reset() });
    }
};
const revoke = (row) => {
    if (window.confirm(`Sign out ${row.device}?`)) router.delete(row.revoke_url, { preserveScroll: true });
};
const revokeOthers = () => {
    if (window.confirm("Sign out every other device?")) router.delete(props.securityUrls.revoke_others, { preserveScroll: true });
};
async function copyText(text, flag) {
    try {
        await navigator.clipboard.writeText(text);
        flag.value = true;
    } catch {
        flag.value = false;
    }
}
function download() {
    const url = URL.createObjectURL(new Blob([recoveryCodesText(props.recoveryCodes)], { type: "text/plain" }));
    const link = Object.assign(document.createElement("a"), { href: url, download: "perysee-recovery-codes.txt" });
    link.click();
    URL.revokeObjectURL(url);
}
const when = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" }) : "never");
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">Account</p>
            <h1>Security</h1>
            <p class="text-sm text-muted">Two-factor authentication, connected devices and recent activity.</p>
        </div>
    </div>
    <UAlert
        v-if="twoFactor.required && !twoFactor.enabled"
        class="mb-5"
        color="warning"
        variant="soft"
        icon="i-tabler-shield-lock"
        title="Two-factor authentication is required"
        description="Your administrator requires it for every account. Set it up below to continue using Perysee."
        role="alert"
    />

    <UCard>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium">Two-factor authentication</h2>
                <UBadge :label="twoFactor.enabled ? 'On' : 'Off'" :color="twoFactor.enabled ? 'success' : 'neutral'" variant="subtle" />
            </div>
        </template>

        <div v-if="recoveryCodes" class="mb-6">
            <UAlert color="warning" variant="soft" icon="i-tabler-key" title="Save your recovery codes now" description="Each code works once if you lose your phone. They are shown only this time." />
            <ul class="mt-4 grid grid-cols-2 gap-2 font-mono text-sm" aria-label="Recovery codes">
                <li v-for="code in recoveryCodes" :key="code" class="rounded-md border border-default px-3 py-2 select-all tabular-nums">{{ code }}</li>
            </ul>
            <div class="mt-4 flex flex-wrap gap-2">
                <UButton :label="copied ? 'Copied' : 'Copy codes'" :icon="copied ? 'i-tabler-check' : 'i-tabler-copy'" color="neutral" variant="outline" size="sm" @click="copyText(recoveryCodes.join('\n'), copied)" />
                <UButton label="Download" icon="i-tabler-download" color="neutral" variant="outline" size="sm" @click="download" />
            </div>
        </div>

        <template v-if="twoFactor.enabled">
            <p class="text-sm">Sign-in asks for a code from your authenticator app after the password.</p>
            <p class="text-sm mt-2" :class="lowCodes ? 'text-warning' : 'text-muted'" role="status">
                {{ twoFactor.remaining_codes }} recovery {{ twoFactor.remaining_codes === 1 ? "code" : "codes" }} left<template v-if="lowCodes"> — generate new ones</template>.
            </p>
            <UForm :state="manage" class="mt-5 flex flex-wrap items-end gap-3" @submit="regenerate">
                <UFormField label="Current password" name="current_password" :error="manage.errors.current_password" description="Needed for either action.">
                    <UInput v-model="manage.current_password" type="password" autocomplete="current-password" class="w-72 max-w-full" />
                </UFormField>
                <UButton type="submit" label="New recovery codes" icon="i-tabler-refresh" color="neutral" variant="outline" :loading="manage.processing" />
                <UButton v-if="!twoFactor.required" label="Turn off" icon="i-tabler-shield-off" color="error" variant="outline" :disabled="manage.processing" @click="disable" />
            </UForm>
        </template>

        <template v-else-if="twoFactor.setup">
            <ol class="space-y-6 text-sm">
                <li>
                    <p class="font-medium">1. Add this account to your authenticator app</p>
                    <div class="mt-3 flex flex-wrap items-start gap-6">
                        <div v-if="twoFactor.setup.qr" class="qr-frame shrink-0" role="img" aria-label="QR code to scan with your authenticator app" v-html="twoFactor.setup.qr" />
                        <div class="min-w-0">
                            <p class="text-muted">Cannot scan? Enter this key instead:</p>
                            <code class="mt-1 block select-all font-mono text-base tracking-wide" tabindex="0" :aria-label="'Setup key ' + groupSecret(twoFactor.setup.secret)"><span v-for="(group, index) in groupSecret(twoFactor.setup.secret).split(' ')" :key="index" class="inline-block mr-2">{{ group }}</span></code>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <UButton :label="copiedKey ? 'Copied' : 'Copy key'" :icon="copiedKey ? 'i-tabler-check' : 'i-tabler-copy'" color="neutral" variant="outline" size="sm" @click="copyText(twoFactor.setup.secret, copiedKey)" />
                                <UButton label="Open in app" icon="i-tabler-device-mobile" :to="twoFactor.setup.uri" external color="neutral" variant="outline" size="sm" />
                            </div>
                        </div>
                    </div>
                </li>
                <li>
                    <p class="font-medium">2. Enter the 6-digit code it shows</p>
                    <UForm :state="confirm" class="mt-3 flex flex-wrap items-end gap-3" @submit="activate">
                        <UFormField label="Code" name="code" :error="confirm.errors.code">
                            <UInput v-model="confirm.code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" placeholder="123456" autofocus class="w-40" />
                        </UFormField>
                        <UButton type="submit" label="Turn on" :loading="confirm.processing" />
                    </UForm>
                </li>
            </ol>
        </template>

        <template v-else>
            <p class="text-sm text-muted">Ask for a code from an authenticator app (such as Aegis, 2FAS, Google Authenticator or 1Password) each time you sign in.</p>
            <UForm :state="start" class="mt-5 flex flex-wrap items-end gap-3" @submit="begin">
                <UFormField label="Current password" name="current_password" :error="start.errors.current_password">
                    <UInput v-model="start.current_password" type="password" autocomplete="current-password" class="w-72 max-w-full" />
                </UFormField>
                <UButton type="submit" label="Set up" icon="i-tabler-shield-lock" :loading="start.processing" />
            </UForm>
        </template>
    </UCard>

    <UCard class="mt-6">
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium">Connected devices</h2>
                <UButton v-if="others" label="Sign out other devices" icon="i-tabler-logout" color="neutral" variant="outline" size="sm" @click="revokeOthers" />
            </div>
        </template>
        <UAlert v-if="errors?.session" color="error" variant="soft" :description="errors.session" class="mb-4" role="alert" />
        <ul class="divide-y divide-default" aria-label="Connected devices">
            <li v-for="row in openSessions" :key="row.id" class="py-3 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium truncate">
                        {{ row.device }}
                        <UBadge v-if="row.current" label="This device" color="primary" variant="subtle" class="ml-1 align-middle" />
                    </p>
                    <p class="text-xs text-muted">{{ networkLabel(row.ip) }} · last active {{ when(row.last_seen_at) }}</p>
                </div>
                <UButton v-if="!row.current" label="Sign out" icon="i-tabler-x" color="neutral" variant="ghost" size="sm" :aria-label="'Sign out ' + row.device" @click="revoke(row)" />
            </li>
        </ul>
        <p class="data-note mt-4">A device appears here after it signs in and drops out once its session has expired. Addresses are shortened to their network.</p>
    </UCard>

    <UCard class="mt-6">
        <template #header><h2 class="text-sm font-medium">Recent activity</h2></template>
        <AccessEntries :entries="accessEntries" only="accessEntries" />
        <p class="data-note mt-4">Entries are kept for 90 days.</p>
    </UCard>
</template>
<style scoped>
.qr-frame {
    width: 200px;
    height: 200px;
    background: #fff;
    border: 1px solid var(--ui-border);
    border-radius: 0.5rem;
    overflow: hidden;
}
.qr-frame :deep(svg) {
    display: block;
    width: 100%;
    height: 100%;
}
</style>
