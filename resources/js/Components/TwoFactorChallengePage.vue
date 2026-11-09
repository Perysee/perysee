<script setup>
import { nextTick, ref } from "vue";
import { Link, useForm, usePage } from "@inertiajs/vue3";
const props = defineProps(["challengeUrl"]);
const page = usePage();
const form = useForm({ code: "" });
const recovery = ref(false);
const field = ref(null);
// Switching the kind of code re-creates the field: put the keyboard back in it.
const toggle = async () => {
    recovery.value = !recovery.value;
    form.reset();
    form.clearErrors();
    await nextTick();
    field.value?.querySelector("input")?.focus();
};
const submit = () => form.post(props.challengeUrl, { onFinish: () => form.reset("code") });
</script>
<template>
    <div class="mb-7 text-center">
        <h1 class="text-3xl tracking-tight text-highlighted font-medium mt-2">Enter your code</h1>
        <p class="text-sm text-muted mt-2">
            {{ recovery ? "Use one of the recovery codes you saved. Each works once." : "Open your authenticator app and type the 6-digit code." }}
        </p>
    </div>
    <UCard>
        <div ref="field">
        <UForm :state="form" class="space-y-5" @submit="submit">
            <UFormField :label="recovery ? 'Recovery code' : 'Authentication code'" name="code" :error="form.errors.code" required>
                <UInput
                    :key="recovery ? 'recovery' : 'totp'"
                    v-model="form.code"
                    :inputmode="recovery ? 'text' : 'numeric'"
                    autocomplete="one-time-code"
                    :placeholder="recovery ? 'XXXXX-XXXXX' : '123456'"
                    :maxlength="recovery ? 16 : 7"
                    spellcheck="false"
                    autofocus
                    class="w-full" />
            </UFormField>
            <UButton type="submit" label="Verify" :loading="form.processing" block />
        </UForm>
        </div>
        <div class="mt-5 flex items-center justify-between gap-3 text-sm">
            <button type="button" class="underline text-muted hover:text-default" @click="toggle">
                {{ recovery ? "Use an authenticator code" : "Use a recovery code" }}
            </button>
            <Link :href="page.props.links.login" class="underline text-muted hover:text-default">Back to sign in</Link>
        </div>
    </UCard>
</template>
