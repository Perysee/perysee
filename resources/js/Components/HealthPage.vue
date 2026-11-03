<script setup>
import { computed } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
const props = defineProps(["health", "geolocation", "cron", "moduleCards"]);
const page = usePage();
const retention = useForm({
    raw_retention_days: props.health.raw_retention_days,
    stats_retention_days: props.health.stats_retention_days,
});
const upload = useForm({ database: null });
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
const processing = computed(() =>
    Object.entries({
        heartbeat: "Last scheduler heartbeat",
        aggregate_success: "Last successful consolidation",
        oldest_pending: "Oldest pending event",
        collection_failures: "Collection failures",
        collection_rejections: "Collection rejections",
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
        description="Check the cron in your hosting panel. Unprocessed events are preserved."
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
                            : (health.database_bytes / 1048576).toFixed(1),
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
                Check that the heartbeat advances after saving your cron.
            </p></UCard
        >
    </div>
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
                deduplication records remain until the site is deleted.
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
