<script setup>
import { useForm } from "@inertiajs/vue3";
import AccessEntries from "./AccessEntries.vue";
const props = defineProps(["requireTwoFactor", "adminHasTwoFactor", "instanceEntries", "retentionDays", "securityUpdateUrl", "accountSecurityUrl", "errors"]);
const form = useForm({ require_two_factor: props.requireTwoFactor });
const save = () => form.put(props.securityUpdateUrl, { preserveScroll: true });
</script>
<template>
    <div class="page-heading">
        <div>
            <h1>Security</h1>
            <p class="text-sm text-muted">Two-factor policy and the access log of this instance.</p>
        </div>
    </div>
    <UCard>
        <template #header><h2 class="text-sm font-medium">Two-factor authentication</h2></template>
        <UForm :state="form" class="space-y-4" @submit="save">
            <USwitch v-model="form.require_two_factor" label="Require two-factor authentication for every account" description="Takes effect immediately, with no grace period: an account without it is sent to set it up at its next page." :disabled="!adminHasTwoFactor && !form.require_two_factor" />
            <UAlert v-if="!adminHasTwoFactor" color="warning" variant="soft" icon="i-tabler-shield-lock" role="status">
                <template #description>
                    Turn it on for your own account first, or you would be locked out.
                    <a :href="accountSecurityUrl" class="underline">Set up two-factor authentication</a>.
                </template>
            </UAlert>
            <p v-if="form.errors.require_two_factor" class="text-sm text-error" role="alert">{{ form.errors.require_two_factor }}</p>
            <UButton type="submit" label="Save" :loading="form.processing" :disabled="!form.isDirty" />
        </UForm>
        <p class="data-note mt-4">An administrator who lost both phone and recovery codes can reset their own setup from the server with <code>php artisan perysee:recover-admin --reset-2fa</code>.</p>
    </UCard>
    <UCard class="mt-6">
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium">Access log</h2>
                <span class="text-xs text-muted">{{ instanceEntries.total }} {{ instanceEntries.total === 1 ? "entry" : "entries" }}</span>
            </div>
        </template>
        <AccessEntries :entries="instanceEntries" only="instanceEntries" show-account />
        <p class="data-note mt-4">Sign-ins, two-factor changes, accepted invitations, share links and revoked sessions of the people who use this back office. Addresses are shortened (/24 for IPv4, /48 for IPv6) and entries are deleted after {{ retentionDays }} days. Visitors of your sites are never recorded here.</p>
    </UCard>
</template>
