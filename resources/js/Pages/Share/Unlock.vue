<script setup>
import { useForm, usePage } from "@inertiajs/vue3";
const page = usePage();
const form = useForm({ password: "" });
const submit = () => form.post(`${page.props.currentUrl}/unlock`, { onFinish: () => form.reset("password") });
</script>
<template>
    <UApp>
        <main class="mx-auto flex min-h-screen max-w-sm flex-col justify-center gap-4 px-4">
            <h1 class="text-lg font-semibold">This shared report is protected</h1>
            <form class="space-y-3" @submit.prevent="submit">
                <UFormField label="Password" :error="form.errors.password">
                    <UInput v-model="form.password" type="password" autocomplete="off" maxlength="128" class="w-full" autofocus />
                </UFormField>
                <UButton type="submit" label="View report" block :loading="form.processing" />
            </form>
        </main>
    </UApp>
</template>
