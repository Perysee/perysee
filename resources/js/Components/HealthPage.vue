<script setup>
import { computed } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
const props = defineProps(["health", "geolocation", "cron", "moduleCards", "moduleFailures", "softwareUpdate"]);
const page = usePage();
const retention = useForm({
    raw_retention_days: props.health.raw_retention_days,
    stats_retention_days: props.health.stats_retention_days,
});
const quota = useForm({
    storage_quota_mib:
        props.health.storage_quota_bytes === null
            ? ""
            : Math.round(props.health.storage_quota_bytes / 1048576),
});
const projection = computed(() => props.health.storage_projection);
const budgetState = computed(() => props.health.storage_budget);
const budget = useForm({
    storage_budget_mib:
        props.health.storage_budget.budget_bytes === null
            ? ""
            : Math.round(props.health.storage_budget.budget_bytes / 1048576),
    storage_mode: props.health.storage_budget.mode,
});
const modeOptions = [
    { label: "Off: alert only, nothing is deleted", value: "off" },
    { label: "Ask: wait for my agreement before each reduction", value: "ask" },
    { label: "Automatic: reduce retention by itself", value: "auto" },
];
const reduction = (caps) =>
    [
        caps.raw_days === null ? null : `raw events ${caps.raw_days} days`,
        caps.hourly_days === null ? null : `hourly aggregates ${caps.hourly_days} days`,
    ]
        .filter(Boolean)
        .join(", ");
const agree = (tier) =>
    router.put(page.props.links["health.storage.agreement"], { tier }, { preserveScroll: true });
const upload = useForm({ database: null });
const updateRequest = useForm({ password: "", dump: "auto" });
const updateCheck = useForm({});
const updateBusy = computed(
    () =>
        props.softwareUpdate.status.state === "running" ||
        props.softwareUpdate.requested !== null,
);
const updateStates = {
    running: ["info", "Updating"],
    succeeded: ["success", "Updated"],
    failed: ["warning", "Failed, nothing lost"],
    needs_attention: ["error", "Needs attention"],
    rolled_back: ["neutral", "Rolled back"],
};
const dumpOptions = computed(() => [
    { label: "Best available", value: "auto" },
    ...props.softwareUpdate.methods
        .filter((method) => method.available)
        .map((method) => ({ label: method.label, value: method.name })),
]);
const cronLate = computed(
    () =>
        !props.health.heartbeat ||
        Date.now() - new Date(props.health.heartbeat).getTime() > 600000,
);
const backlogLate = computed(
    () =>
        props.health.oldest_pending &&
        Date.now() - new Date(props.health.oldest_pending + "Z").getTime() >
            900000,
);
const mebibytes = (bytes) => (bytes / 1048576).toFixed(1);
const largestTables = computed(() =>
    props.health.largest_tables.map((table) => ({
        name: table.name,
        size: mebibytes(table.data_bytes + table.index_bytes),
        rows: table.row_estimate === null ? "—" : table.row_estimate.toLocaleString(),
    })),
);
const processing = computed(() =>
    Object.entries({
        heartbeat: "Last scheduler heartbeat",
        aggregate_success: "Last successful consolidation",
        oldest_pending: "Oldest pending event",
        collection_failures: "Collection failures",
        collection_rejections: "Collection rejections",
        property_truncations: "Event properties shortened",
        deleting_sites: "Sites being deleted",
    }).map(([key, label]) => ({
        label,
        value: props.health[key] ?? "Unavailable",
    })),
);
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">Administration</p>
            <h1>Instance health</h1>
            <p class="text-sm text-muted">
                Collection, consolidation and data retention.
            </p>
        </div>
        <UBadge
            :color="cronLate || backlogLate ? 'warning' : 'success'"
            variant="subtle"
            >{{
                cronLate
                    ? "Check your scheduler"
                    : backlogLate
                      ? "Consolidation delayed"
                      : "Scheduler active"
            }}</UBadge
        >
    </div>
    <UAlert
        v-if="cronLate"
        class="mb-5"
        color="warning"
        variant="subtle"
        icon="i-tabler-clock"
        title="Scheduler heartbeat missing or late"
        description="The scheduler has not reported in. Add the cron job shown under “Scheduled task” in your hosting panel, then allow up to five minutes: the heartbeat is recorded every five minutes. Unprocessed events are preserved."
    /><UAlert
        v-if="health.aggregate_error"
        color="error"
        variant="subtle"
        title="Last aggregation error"
        :description="
            health.aggregate_error.type + ' · ' + health.aggregate_error.at
        "
        class="mb-5"
    />
    <UAlert color="neutral" class="mb-5" title="Session-flow calculations" :description="`${health.journey_reports_pending} pending · ${health.journey_reports_errors} failed`" />
    <UAlert v-if="health.segments_rebuild" color="neutral" class="mb-5" title="Cross-filter reconstruction" :description="`${health.segments_rebuild.pending} events pending · last run ${health.segments_rebuild.at}`" />
    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-6">
        <UCard
            v-for="item in [
                {
                    label: 'Pending events',
                    value: health.backlog,
                    unit: 'waiting for consolidation',
                },
                {
                    label: 'Estimated storage',
                    value:
                        health.database_bytes === null
                            ? '—'
                            : mebibytes(health.database_bytes),
                    unit: 'MiB · database and indexes',
                },
                {
                    label: 'Raw retention',
                    value: health.raw_retention_days,
                    unit: 'days · processed events only',
                },
                {
                    label: 'Statistics retention',
                    value: health.stats_retention_days,
                    unit: 'days · consolidated reports',
                },
            ]"
            :key="item.label"
            ><span class="metric-label">{{ item.label }}</span
            ><strong class="metric-value">{{ item.value }}</strong>
            <p class="text-[11px] text-muted mt-2">{{ item.unit }}</p></UCard
        >
    </div>
    <UCard v-if="largestTables.length" class="mb-6" :ui="{ body: 'p-0 sm:p-0' }"
        ><template #header
            ><h2 class="text-sm font-medium">Largest tables</h2></template
        ><UTable
            :data="largestTables"
            :columns="[
                { accessorKey: 'name', header: 'Table' },
                { accessorKey: 'size', header: 'MiB · data and indexes' },
                { accessorKey: 'rows', header: 'Rows (estimate)' },
            ]"
    /></UCard>
    <UCard class="mb-6"
        ><template #header
            ><h2 class="text-sm font-medium">Storage quota</h2></template
        ><UAlert
            v-if="projection.message"
            class="mb-5"
            :color="projection.status === 'reached' || (projection.status === 'projected' && projection.days < 30) ? 'warning' : 'neutral'"
            variant="subtle"
            :description="projection.message"
        /><UForm
            :state="quota"
            class="space-y-5"
            @submit="
                quota.put(page.props.links['health.storage'], {
                    preserveScroll: true,
                })
            "
            ><UFormField
                label="Quota (MiB)"
                name="storage_quota_mib"
                :error="quota.errors.storage_quota_mib"
                ><UInput
                    v-model="quota.storage_quota_mib"
                    type="number"
                    min="1"
                    max="16777216"
                    class="w-full"
            /></UFormField>
            <p class="data-note">
                The disk space your hosting plan gives the database. Leave empty
                for no quota. The date is estimated from the last 30 days of
                daily measurements, once 7 days are available.
            </p>
            <UButton
                type="submit"
                label="Save quota"
                :loading="quota.processing"
        /></UForm>
    </UCard>
    <UCard class="mb-6"
        ><template #header
            ><div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium">Storage budget</h2>
                <UBadge
                    :color="budgetState.tier > 0 ? 'warning' : 'neutral'"
                    variant="subtle"
                    >Tier {{ budgetState.tier }} · mode {{ budgetState.mode }}</UBadge
                >
            </div></template
        ><UAlert
            v-if="budgetState.tier > 0"
            class="mb-5"
            color="warning"
            variant="subtle"
            :title="`Retention is reduced (tier ${budgetState.tier})`"
            :description="`Kept at most: ${reduction(budgetState.caps)}. Your own settings are unchanged and apply again once the database is under 80% of the budget.`"
        /><UAlert
            v-if="budgetState.proposal"
            class="mb-5"
            color="warning"
            variant="subtle"
            icon="i-tabler-database-exclamation"
            :title="`The database is at ${budgetState.ratio_percent}% of its budget`"
            :description="`Nothing is deleted until you agree. Tier ${budgetState.proposal.tier} keeps at most: ${reduction(budgetState.proposal)}. Data older than that is removed by the hourly purges.`"
        >
            <template #actions
                ><UButton
                    :label="`Agree to tier ${budgetState.proposal.tier}`"
                    color="warning"
                    @click="agree(budgetState.proposal.tier)"
            /></template>
        </UAlert>
        <p v-if="budgetState.ratio_percent !== null" class="text-sm mb-5">
            Using {{ budgetState.ratio_percent }}% of the budget<span
                v-if="budgetState.checked_at"
                class="text-muted"
            >
                · measured {{ budgetState.checked_at }}</span
            >.
        </p>
        <p v-if="budget.errors.tier" class="text-sm text-error mb-5">
            {{ budget.errors.tier }}
        </p>
        <UForm
            :state="budget"
            class="space-y-5"
            @submit="
                budget.put(page.props.links['health.storage.budget'], {
                    preserveScroll: true,
                })
            "
            ><div class="form-grid">
                <UFormField
                    label="Budget (MiB)"
                    name="storage_budget_mib"
                    :error="budget.errors.storage_budget_mib"
                    ><UInput
                        v-model="budget.storage_budget_mib"
                        type="number"
                        min="1"
                        max="16777216"
                        class="w-full" /></UFormField
                ><UFormField
                    label="When the budget is reached"
                    name="storage_mode"
                    :error="budget.errors.storage_mode"
                    ><USelect
                        v-model="budget.storage_mode"
                        :items="modeOptions"
                        class="w-full"
                /></UFormField>
            </div>
            <p class="data-note">
                A size you want to stay under, checked every hour. At 100% the
                retention is reduced one tier at a time, at most once a day:
                raw events 30 days, then 7 days, then hourly aggregates 90 days,
                then 30 days. Your retention settings are never rewritten and a
                tier can only shorten them. Everything returns to normal under
                80%. Daily statistics are never touched. Reduced data is
                deleted for good: take a backup first. Choosing Automatic is
                recorded in the access log.
            </p>
            <UButton
                type="submit"
                label="Save budget"
                :loading="budget.processing"
        /></UForm>
    </UCard>
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <UCard :ui="{ body: 'p-0 sm:p-0' }"
            ><template #header
                ><h2 class="text-sm font-medium">
                    Processing status
                </h2></template
            ><UTable
                :data="processing"
                :columns="[
                    { accessorKey: 'label', header: 'Signal' },
                    { accessorKey: 'value', header: 'Value (UTC)' },
                ]" /></UCard
        ><UCard
            ><template #header
                ><h2 class="text-sm font-medium">Scheduled task</h2></template
            >
            <p class="data-note mb-4">
                Run every minute, or every five minutes if your hosting limits
                the frequency. No permanent worker is needed.
            </p>
            <pre class="code-block"><code>{{cron}}</code></pre>
            <p class="data-note mt-4">
                Add this line to the crontab of the account that owns the
                application. It uses the PHP command line that matches this
                site. If the heartbeat does not advance within five minutes,
                run the part after “&amp;&amp;” in a terminal to see the error;
                errors are also kept in
                <code>storage/logs/cron.log</code>.
            </p></UCard
        >
    </div>
    <UCard class="mb-6"
        ><template #header
            ><div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium">Software update</h2>
                <UBadge
                    :color="softwareUpdate.available ? 'info' : 'success'"
                    variant="subtle"
                    >{{
                        softwareUpdate.available
                            ? "Version " +
                              softwareUpdate.available.tag +
                              " available"
                            : "Up to date"
                    }}</UBadge
                >
            </div></template
        >
        <dl class="space-y-2 text-sm mb-4">
            <div class="flex justify-between gap-4">
                <dt class="text-muted">Installed version</dt>
                <dd>{{ softwareUpdate.current }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-muted">Last check (UTC)</dt>
                <dd>{{ softwareUpdate.checked_at ?? "Never" }}</dd>
            </div>
        </dl>
        <UAlert
            v-if="softwareUpdate.error"
            class="mb-4"
            color="warning"
            variant="subtle"
            title="The last check failed"
            :description="softwareUpdate.error"
        />
        <UAlert
            v-if="softwareUpdate.status.state !== 'idle' && softwareUpdate.status.message"
            class="mb-4"
            :color="(updateStates[softwareUpdate.status.state] ?? ['neutral'])[0]"
            variant="subtle"
            :title="(updateStates[softwareUpdate.status.state] ?? [null, 'Last update'])[1]"
            :description="softwareUpdate.status.message"
        />
        <UAlert
            v-if="softwareUpdate.requested"
            class="mb-4"
            color="info"
            variant="subtle"
            icon="i-tabler-clock"
            title="Update requested"
            description="It starts within a minute. The site is unavailable while it runs."
        />
        <UAlert
            v-if="updateCheck.errors.update || updateRequest.errors.update"
            class="mb-4"
            color="error"
            variant="subtle"
            :description="updateCheck.errors.update || updateRequest.errors.update"
        />
        <div class="flex flex-wrap gap-3 mb-4">
            <UButton
                label="Check for updates"
                color="neutral"
                variant="outline"
                :disabled="!softwareUpdate.enabled || updateBusy"
                :loading="updateCheck.processing"
                @click="updateCheck.post(page.props.links['health.updates.check'], { preserveScroll: true })"
            />
            <UButton
                v-if="softwareUpdate.requested"
                label="Cancel the request"
                color="neutral"
                variant="outline"
                @click="router.delete(page.props.links['health.updates.request'], { preserveScroll: true })"
            />
        </div>
        <UForm
            v-if="softwareUpdate.available && !updateBusy"
            :state="updateRequest"
            class="space-y-4"
            @submit="updateRequest.post(page.props.links['health.updates.request'], { preserveScroll: true, onSuccess: () => updateRequest.reset('password') })"
            ><div class="form-grid">
                <UFormField
                    label="Your password"
                    name="password"
                    :error="updateRequest.errors.password"
                    ><UInput
                        v-model="updateRequest.password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full" /></UFormField
                ><UFormField
                    label="Database backup"
                    name="dump"
                    :error="updateRequest.errors.dump"
                    ><USelect
                        v-model="updateRequest.dump"
                        :items="dumpOptions"
                        class="w-full"
                /></UFormField>
            </div>
            <p class="data-note">
                The database, the environment file and the private files are
                saved first, the archive is verified against its published
                SHA-256 checksum, and the previous files are restored if the
                update fails. Collection answers 503 for the duration.
            </p>
            <UButton
                type="submit"
                :label="'Update to ' + softwareUpdate.available.tag"
                :loading="updateRequest.processing"
                :disabled="!updateRequest.password"
            />
        </UForm>
        <p class="data-note mt-4">
            Prefer a terminal? Run
            <code>php artisan perysee:update</code>. A rollback is
            <code>php artisan perysee:update:rollback</code>.
        </p></UCard
    >
    <UCard v-if="moduleFailures?.length" class="mb-6">
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium">Module failures</h2>
                <UBadge
                    :color="moduleFailures.some((module) => module.quarantined) ? 'error' : 'warning'"
                    variant="subtle"
                    >{{
                        moduleFailures.some((module) => module.quarantined)
                            ? "In quarantine"
                            : "Needs attention"
                    }}</UBadge
                >
            </div>
        </template>
        <ul class="space-y-3 text-sm">
            <li
                v-for="module in moduleFailures"
                :key="module.id"
                class="flex flex-wrap items-start justify-between gap-2"
            >
                <div>
                    <span class="font-medium">{{ module.name }}</span>
                    <p v-if="module.quarantined" class="text-xs text-muted">
                        Disabled automatically: {{ module.reason }}
                    </p>
                </div>
                <span class="text-muted">{{ module.failures }} failures recorded</span>
            </li>
        </ul>
        <p class="data-note mt-4">
            A module that keeps failing is disabled so that it cannot slow down your
            site. Its data is kept. Enable it again from the Modules page once it is fixed.
        </p>
    </UCard>
    <div v-if="moduleCards?.length" class="grid lg:grid-cols-2 gap-6 mb-6">
        <UCard v-for="card in moduleCards" :key="card.module">
            <template #header>
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-medium">{{ card.title }}</h2>
                    <UBadge
                        :color="card.status === 'ok' ? 'success' : 'warning'"
                        variant="subtle"
                        >{{
                            card.status === "ok" ? "Working" : "Needs attention"
                        }}</UBadge
                    >
                </div>
            </template>
            <dl class="space-y-2 text-sm">
                <div
                    v-for="item in card.items"
                    :key="item.label"
                    class="flex justify-between gap-4"
                >
                    <dt class="text-muted">{{ item.label }}</dt>
                    <dd>{{ item.value }}</dd>
                </div>
            </dl>
        </UCard>
    </div>
    <UCard class="mb-6"
        ><template #header
            ><h2 class="text-sm font-medium">Data retention</h2></template
        ><UForm
            :state="retention"
            class="space-y-5"
            @submit="
                retention.put(page.props.links['health.update'], {
                    preserveScroll: true,
                })
            "
            ><div class="form-grid">
                <UFormField
                    label="Raw events (days)"
                    name="raw_retention_days"
                    :error="retention.errors.raw_retention_days"
                    ><UInput
                        v-model="retention.raw_retention_days"
                        type="number"
                        min="1"
                        max="3650"
                        class="w-full" /></UFormField
                ><UFormField
                    label="Daily statistics (days)"
                    name="stats_retention_days"
                    :error="retention.errors.stats_retention_days"
                    ><UInput
                        v-model="retention.stats_retention_days"
                        type="number"
                        min="1"
                        max="3650"
                        class="w-full"
                /></UFormField>
            </div>
            <p class="data-note">
                Deletion is permanent. Unprocessed events are preserved. Session
                records follow the daily statistics retention. A storage budget
                can shorten these values for a while without changing them.
            </p>
            <UButton
                type="submit"
                label="Save retention"
                :loading="retention.processing" /></UForm
    ></UCard>
    <UCard
        ><template #header
            ><h2 class="text-sm font-medium">
                Local country database
            </h2></template
        ><UBadge
            :color="geolocation.available ? 'success' : 'warning'"
            variant="subtle"
            >{{
                geolocation.available
                    ? "Available · built " + geolocation.built_at
                    : "Unavailable · collection continues without countries"
            }}</UBadge
        ><UForm
            :state="upload"
            class="flex flex-wrap gap-4 items-end my-5"
            @submit="
                upload.post(page.props.links['health.geolocation'], {
                    forceFormData: true,
                    preserveScroll: true,
                })
            "
            ><UFormField
                label="DB-IP Country MMDB · 20 MiB maximum"
                name="database"
                :error="upload.errors.database"
                ><UInput
                    type="file"
                    accept=".mmdb"
                    @change="
                        upload.database = $event.target.files[0]
                    " /></UFormField
            ><UButton
                type="submit"
                label="Import database"
                color="neutral"
                variant="outline"
                :loading="upload.processing"
                :disabled="!upload.database"
        /></UForm>
        <p class="data-note">
            <a href="https://db-ip.com" class="underline" rel="noreferrer"
                >IP Geolocation by DB-IP</a
            >
            · Approximate country data. Raw IP addresses are not stored.
            Imported databases override the bundled edition.
        </p></UCard
    >
    <p class="data-note mt-5">
        This page does not grant access to individual site reports.
    </p>
</template>
