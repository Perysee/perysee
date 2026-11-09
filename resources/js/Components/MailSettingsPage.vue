<script setup>
import { computed, ref, watch } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
const props = defineProps(["mail", "recipient", "testResult"]);
const page = usePage();
const form = useForm({
    enabled: props.mail.enabled,
    host: props.mail.host ?? "",
    port: props.mail.port ?? "",
    encryption: props.mail.encryption,
    username: props.mail.username ?? "",
    password: "",
    remove_password: false,
    from_address: props.mail.from_address ?? "",
    from_name: props.mail.from_name ?? "",
});
const recipient = ref(props.recipient);
const ports = { none: 25, starttls: 587, tls: 465 };
const encryptions = [
    { value: "starttls", label: "STARTTLS (usually port 587)" },
    { value: "tls", label: "TLS / SMTPS (usually port 465)" },
    { value: "none", label: "None (usually port 25)" },
];
const states = {
    saved: ["success", "Configured here"],
    environment: ["info", "Configured by environment"],
    none: ["neutral", "Not configured"],
};
const sources = { saved: "Saved here", environment: "From the environment (.env)", default: "Default" };
const hints = {
    sent: ["success", "Test e-mail sent", "The server accepted the message. Check the inbox of the recipient, and the spam folder."],
    connection: ["error", "Could not connect", "The server could not be reached. Check the host, the port and the encryption, and that your hosting allows outgoing connections to this server."],
    authentication: ["error", "Sign-in refused", "The server refused the user name or the password. Retype the password and try again."],
    sender_rejected: ["error", "Sender refused", "The server does not allow this sender address. Use an address that belongs to the account on that server."],
    other: ["warning", "The message was not accepted", "The server answered in an unexpected way. Details are in the log of this instance, without any password."],
    not_configured: ["warning", "No mail server to test", "Fill in the server, or keep the environment configuration, then try again."],
};
const result = computed(() => (props.testResult ? hints[props.testResult.category] ?? hints.other : null));
const badge = computed(() => states[props.mail.state] ?? states.none);
const portPlaceholder = computed(() => String(ports[form.encryption]));
// A port that is still the default of the previous encryption follows the new choice.
watch(
    () => form.encryption,
    (next, previous) => {
        if (String(form.port) === String(ports[previous]) || form.port === "") form.port = ports[next];
    },
);
// After "Use the environment instead" the fields show the values of the environment again. Validation errors keep what was typed.
watch(
    () => props.mail.saved,
    () => {
        form.defaults({
            enabled: props.mail.enabled,
            host: props.mail.host ?? "",
            port: props.mail.port ?? "",
            encryption: props.mail.encryption,
            username: props.mail.username ?? "",
            password: "",
            remove_password: false,
            from_address: props.mail.from_address ?? "",
            from_name: props.mail.from_name ?? "",
        });
        form.reset();
    },
);
const url = computed(() => page.props.links["settings.mail"]);
const keepsPassword = computed(() => props.mail.password_set && !form.remove_password);
function save() {
    form.transform((data) => data).put(url.value, { preserveScroll: true, onSuccess: () => {
            // A successful submit makes the typed values the form defaults, so reset() would keep the password: clear it by hand.
            form.password = "";
            form.remove_password = false;
        },
    });
}
function sendTest() {
    // The transformation is sticky on an Inertia form: it is removed again so that "Save" never sends the recipient.
    form.transform((data) => ({ ...data, recipient: recipient.value })).post(page.props.links["settings.mail.test"], {
        preserveScroll: true,
        onFinish: () => form.transform((data) => data),
    });
}
function reset() {
    if (confirm("Remove the mail settings saved here and use the environment configuration again?")) router.delete(url.value, { preserveScroll: true });
}
</script>
<template>
    <div class="page-heading">
        <div>
            <h1>Mail</h1>
            <p class="text-sm text-muted">The mail server of this instance, used for team invitations and for future reports and alerts.</p>
        </div>
        <UBadge :color="badge[0]" variant="subtle">{{ badge[1] }}</UBadge>
    </div>
    <div role="status" aria-live="polite">
        <UAlert v-if="result" class="mb-5" :color="result[0]" variant="subtle" :title="result[1]" :description="result[2] + (testResult.category === 'sent' ? ' (' + testResult.to + ')' : '')" />
    </div>
    <UCard>
        <UForm :state="form" class="space-y-5" @submit="save">
            <UFormField label="Use this mail server" name="enabled" :error="form.errors.enabled">
                <USwitch v-model="form.enabled" label="Send e-mails through the server below" description="Off: the environment configuration (.env) is used, if there is one. Without any mail server, invitations give a link to share by hand." />
            </UFormField>
            <div class="form-grid">
                <UFormField label="Host" name="host" :error="form.errors.host" :description="sources[mail.source.host]" required>
                    <UInput v-model="form.host" autocomplete="off" spellcheck="false" placeholder="smtp.example.com" class="w-full" />
                </UFormField>
                <UFormField label="Port" name="port" :error="form.errors.port" :description="sources[mail.source.port]">
                    <UInput v-model="form.port" type="number" min="1" max="65535" :placeholder="portPlaceholder" class="w-full" />
                </UFormField>
            </div>
            <UFormField label="Encryption" name="encryption" :error="form.errors.encryption" :description="sources[mail.source.encryption]">
                <USelect v-model="form.encryption" :items="encryptions" class="w-full" aria-label="Encryption" />
            </UFormField>
            <div class="form-grid">
                <UFormField label="User name" name="username" :error="form.errors.username" :description="sources[mail.source.username]">
                    <UInput v-model="form.username" autocomplete="off" spellcheck="false" class="w-full" />
                </UFormField>
                <UFormField
                    label="Password"
                    name="password"
                    :error="form.errors.password"
                    :description="mail.password_set ? 'A password is stored. It is never shown again.' : 'No password stored.'"
                >
                    <UInput v-model="form.password" type="password" autocomplete="new-password" :disabled="form.remove_password" :placeholder="keepsPassword ? 'Leave empty to keep the stored password' : ''" class="w-full" />
                </UFormField>
            </div>
            <UCheckbox v-if="mail.password_set" v-model="form.remove_password" label="Remove the password" description="Connect without a password." />
            <div class="form-grid">
                <UFormField label="From address" name="from_address" :error="form.errors.from_address" :description="sources[mail.source.from_address]" required>
                    <UInput v-model="form.from_address" type="email" autocomplete="off" placeholder="noreply@example.com" class="w-full" />
                </UFormField>
                <UFormField label="From name" name="from_name" :error="form.errors.from_name" :description="mail.source.from_name === 'default' ? 'Defaults to the application name' : sources[mail.source.from_name]">
                    <UInput v-model="form.from_name" autocomplete="off" class="w-full" />
                </UFormField>
            </div>
            <p class="data-note">
                The connection times out after {{ mail.timeout }} seconds. This server connects to the host you enter, with the same level of trust as the database host given to the installer: only administrators can change it. The password is stored encrypted and is not sent back to the browser; it is never reused for another host, port or user.
            </p>
            <div class="flex flex-wrap gap-3">
                <UButton type="submit" label="Save mail settings" :loading="form.processing" />
                <UButton v-if="mail.saved" type="button" color="neutral" variant="outline" label="Use the environment instead" @click="reset" />
            </div>
        </UForm>
    </UCard>
    <UCard class="mt-6">
        <template #header><h2 class="text-sm font-medium">Send a test e-mail</h2></template>
        <form class="grid sm:grid-cols-[1fr_auto] gap-3 items-end" @submit.prevent="sendTest">
            <UFormField label="Recipient" name="recipient" :error="form.errors.recipient">
                <UInput v-model="recipient" type="email" autocomplete="off" class="w-full" />
            </UFormField>
            <UButton type="submit" color="neutral" variant="outline" label="Send a test e-mail" :loading="form.processing" />
        </form>
        <p class="data-note mt-4">The test uses the values typed above, even if they are not saved yet. Team invitations use this server as soon as it is saved and switched on.</p>
    </UCard>
</template>
