<script setup>
import { useForm, usePage } from "@inertiajs/vue3";
const props = defineProps(["invitation", "errors"]);
const page = usePage();
const signedIn = !!page.props.auth;
const form = useForm({ name: "", password: "" });
const accept = () => form.post(props.invitation.accept_url, { onFinish: () => form.reset("password") });
</script>
<template>
    <div class="mb-7 text-center">
        <p class="eyebrow">Invitation</p>
        <h1 class="text-3xl tracking-tight text-highlighted font-medium mt-2">Join {{ invitation.site }}</h1>
        <p class="text-sm text-muted mt-2">
            You were invited as {{ invitation.role === "owner" ? "an owner" : "a viewer" }} of {{ invitation.domain }}.
        </p>
    </div>
    <UCard>
        <UAlert v-if="errors?.invitation" color="error" variant="soft" :description="errors.invitation" class="mb-5" role="alert" />
        <UForm v-if="signedIn" :state="form" class="space-y-5" @submit="accept">
            <p class="text-sm text-muted">Signed in as {{ page.props.auth.email }}.</p>
            <UButton type="submit" label="Accept invitation" :loading="form.processing" block />
        </UForm>
        <UForm v-else :state="form" class="space-y-5" @submit="accept">
            <UFormField label="Email" name="email">
                <UInput :model-value="invitation.email" type="email" disabled class="w-full" />
            </UFormField>
            <UFormField label="Your name" name="name" :error="form.errors.name" required>
                <UInput v-model="form.name" autocomplete="name" autofocus class="w-full" />
            </UFormField>
            <UFormField label="Password" name="password" :error="form.errors.password" description="At least 12 characters." required>
                <UInput v-model="form.password" type="password" minlength="12" autocomplete="new-password" class="w-full" />
            </UFormField>
            <UButton type="submit" label="Create account and join" :loading="form.processing" block />
            <p class="text-xs text-muted text-center">
                Already have an account with this address?
                <a :href="page.props.links.login" class="underline">Sign in</a>, then open this link again.
            </p>
        </UForm>
    </UCard>
</template>
