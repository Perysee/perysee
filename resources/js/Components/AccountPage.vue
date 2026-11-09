<script setup>
import { useForm, usePage } from "@inertiajs/vue3";
const props = defineProps(["screen"]);
const page = usePage();
const login = useForm({ email: "", password: "" });
const password = useForm({
    current_password: "",
    password: "",
    password_confirmation: "",
});
const signIn = () =>
    login.post(page.props.links["login.store"], {
        onFinish: () => login.reset("password"),
    });
const update = () =>
    password.put(page.props.links["password.update"], {
        onSuccess: () => password.reset(),
        onError: () =>
            password.reset(
                "current_password",
                "password",
                "password_confirmation",
            ),
    });
</script>
<template>
    <template v-if="screen === 'auth.login'"
        ><div class="mb-7 text-center">
            <h1
                class="text-3xl tracking-tight text-highlighted font-medium mt-2"
            >
                Sign in to {{ page.props.branding.name }}
            </h1>
            <p class="text-sm text-muted mt-2">Your sites, in one place.</p>
        </div>
        <UCard
            ><UForm :state="login" class="space-y-5" @submit="signIn"
                ><UFormField
                    label="Email"
                    name="email"
                    :error="login.errors.email"
                    required
                    ><UInput
                        v-model="login.email"
                        type="email"
                        autocomplete="username"
                        autofocus
                        class="w-full" /></UFormField
                ><UFormField
                    label="Password"
                    name="password"
                    :error="login.errors.password"
                    required
                    ><UInput
                        v-model="login.password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full" /></UFormField
                ><UButton
                    type="submit"
                    label="Sign in"
                    :loading="login.processing"
                    block /></UForm
        ></UCard>
        <p class="text-xs text-muted text-center mt-5">
            Accounts are managed by the instance administrator.<br />Public
            registration is closed.
        </p></template
    >
    <template v-else
        ><div class="page-heading">
            <div>
                <h1>Change password</h1>
                <p class="text-sm text-muted">Keep your account secure.</p>
            </div>
        </div>
        <UCard
            ><UForm :state="password" class="grid gap-5 lg:grid-cols-3 items-start" @submit="update"
                ><UFormField
                    label="Current password"
                    name="current_password"
                    :error="password.errors.current_password"
                    required
                    ><UInput
                        v-model="password.current_password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full" /></UFormField
                ><UFormField
                    label="New password"
                    name="password"
                    :error="password.errors.password"
                    help="At least 12 characters."
                    required
                    ><UInput
                        v-model="password.password"
                        type="password"
                        minlength="12"
                        autocomplete="new-password"
                        class="w-full" /></UFormField
                ><UFormField
                    label="Confirm password"
                    name="password_confirmation"
                    :error="password.errors.password_confirmation"
                    required
                    ><UInput
                        v-model="password.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        class="w-full" /></UFormField
                ><UButton
                    type="submit"
                    label="Update password"
                    class="justify-self-start lg:col-span-3"
                    :loading="password.processing" /></UForm></UCard
    ></template>
</template>
