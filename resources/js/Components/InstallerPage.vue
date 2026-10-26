<script setup>
import { computed } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
const props = defineProps([
    "checks",
    "authorized",
    "migrated",
    "databaseDefaults",
]);
const page = usePage();
const step = computed(() => (!props.authorized ? 0 : !props.migrated ? 1 : 2));
const secret = useForm({ installation_secret: "" });
const database = useForm({
    db_host: props.databaseDefaults?.host || "localhost",
    db_port: props.databaseDefaults?.port || 3306,
    db_database: props.databaseDefaults?.database || "",
    db_username: props.databaseDefaults?.username || "",
    db_password: "",
});
const admin = useForm({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
});
const ready = computed(() => props.checks.every((check) => check.ok));
</script>
<template>
    <div class="text-center mb-8">
        <p class="eyebrow">Welcome to your instance</p>
        <h1 class="text-3xl tracking-tight font-medium text-highlighted mt-2">
            Install Perysee
        </h1>
        <p class="text-sm text-muted mt-2">
            A few steps to make this space yours.
        </p>
    </div>
    <div class="grid grid-cols-3 gap-2 mb-6">
        <div
            v-for="(label, index) in [
                'Compatibility',
                'Database',
                'Administrator',
            ]"
            :key="label"
            class="text-xs p-3 border-t-2"
            :class="
                step === index
                    ? 'border-primary text-primary'
                    : 'border-default text-muted'
            "
        >
            {{ index + 1 }}. {{ label }}
        </div>
    </div>
    <UCard class="mb-6"
        ><template #header
            ><h2 class="text-sm font-medium">Instance checks</h2></template
        >
        <ul class="space-y-3">
            <li
                v-for="check in checks"
                :key="check.label"
                class="flex gap-3 items-start text-xs"
            >
                <UIcon
                    :name="
                        check.ok
                            ? 'i-tabler-circle-check'
                            : 'i-tabler-alert-triangle'
                    "
                    :class="check.ok ? 'text-success' : 'text-warning'"
                    class="size-4 shrink-0"
                /><span>{{ check.label }}</span>
            </li>
        </ul>
        <p class="data-note mt-5">
            The document root must end in /public. Keep .env, vendor, storage
            and backups outside it. The installer cannot audit every server
            alias.
        </p></UCard
    >
    <UCard v-if="!authorized"
        ><template #header
            ><h2 class="text-sm font-medium">
                Verify instance ownership
            </h2></template
        >
        <p class="text-sm text-muted mb-4">
            Copy the secret from your hosting file manager:
        </p>
        <pre class="code-block mb-5">
storage/app/private/installation/secret</pre
        >
        <UForm
            :state="secret"
            class="space-y-5"
            @submit="
                secret.post(page.props.links['install.unlock'], {
                    onFinish: () => secret.reset(),
                })
            "
            ><UFormField
                label="Installation secret"
                name="installation_secret"
                :error="secret.errors.installation_secret"
                description="This file is outside public/. Never share the secret or put it in a URL."
                required
                ><UInput
                    v-model="secret.installation_secret"
                    type="password"
                    autocomplete="off"
                    class="w-full" /></UFormField
            ><UButton
                type="submit"
                label="Continue securely"
                icon="i-tabler-shield-check"
                :loading="secret.processing" /></UForm
    ></UCard>
    <UCard v-else-if="!migrated"
        ><template #header
            ><h2 class="text-sm font-medium">
                Connect your database
            </h2></template
        >
        <p class="data-note mb-5">
            Create a dedicated MariaDB 11.4 database and user in your hosting
            panel first.
        </p>
        <UForm
            :state="database"
            class="space-y-5"
            @submit="
                database.post(page.props.links['install.database'], {
                    onFinish: () => database.reset('db_password'),
                })
            "
            ><UAlert
                v-if="database.errors.database"
                color="error"
                variant="subtle"
                :description="database.errors.database" />
            <div class="form-grid">
                <UFormField
                    label="Database host"
                    name="db_host"
                    :error="database.errors.db_host"
                    required
                    ><UInput
                        v-model="database.db_host"
                        class="w-full" /></UFormField
                ><UFormField
                    label="Port"
                    name="db_port"
                    :error="database.errors.db_port"
                    required
                    ><UInput
                        v-model="database.db_port"
                        type="number"
                        min="1"
                        max="65535"
                        class="w-full"
                /></UFormField>
            </div>
            <UFormField
                label="Database name"
                name="db_database"
                :error="database.errors.db_database"
                required
                ><UInput
                    v-model="database.db_database"
                    class="w-full" /></UFormField
            ><UFormField
                label="Database user"
                name="db_username"
                :error="database.errors.db_username"
                required
                ><UInput
                    v-model="database.db_username"
                    autocomplete="off"
                    class="w-full" /></UFormField
            ><UFormField
                label="Database password"
                name="db_password"
                :error="database.errors.db_password"
                description="Leave blank to keep a saved password for the same database."
                ><UInput
                    v-model="database.db_password"
                    type="password"
                    autocomplete="off"
                    class="w-full" /></UFormField
            ><UButton
                type="submit"
                :disabled="!ready"
                :loading="database.processing"
                label="Connect and prepare database" /></UForm
    ></UCard>
    <UCard v-else
        ><template #header
            ><h2 class="text-sm font-medium">
                Create your administrator
            </h2></template
        ><UForm
            :state="admin"
            class="space-y-5"
            @submit="
                admin.post(page.props.links['install.administrator'], {
                    onFinish: () =>
                        admin.reset('password', 'password_confirmation'),
                })
            "
            ><UFormField
                label="Name"
                name="name"
                :error="admin.errors.name"
                required
                ><UInput
                    v-model="admin.name"
                    autocomplete="name"
                    class="w-full" /></UFormField
            ><UFormField
                label="Email"
                name="email"
                :error="admin.errors.email"
                required
                ><UInput
                    v-model="admin.email"
                    type="email"
                    autocomplete="username"
                    class="w-full" /></UFormField
            ><UFormField
                label="Password"
                name="password"
                :error="admin.errors.password"
                description="Use at least 12 characters."
                required
                ><UInput
                    v-model="admin.password"
                    type="password"
                    minlength="12"
                    autocomplete="new-password"
                    class="w-full" /></UFormField
            ><UFormField
                label="Confirm password"
                name="password_confirmation"
                :error="admin.errors.password_confirmation"
                required
                ><UInput
                    v-model="admin.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    class="w-full" /></UFormField
            ><UButton
                type="submit"
                :disabled="!ready"
                :loading="admin.processing"
                label="Finish installation" /></UForm
    ></UCard>
</template>
