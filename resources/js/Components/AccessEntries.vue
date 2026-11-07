<script setup>
import { router } from "@inertiajs/vue3";
import { describeEntry, networkLabel } from "../security.mjs";
// One page of the access log, shared by the account and the administration screens.
const props = defineProps({
    entries: { type: Object, required: true },
    showAccount: { type: Boolean, default: false },
    only: { type: String, required: true },
});
const when = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" }) : "");
const go = (url) => url && router.get(url, {}, { preserveScroll: true, preserveState: true, only: [props.only] });
</script>
<template>
    <p v-if="!entries.data.length" class="text-sm text-muted">Nothing recorded yet.</p>
    <ul v-else class="divide-y divide-default" aria-label="Access log">
        <li v-for="entry in entries.data" :key="entry.id" class="py-3 flex flex-wrap items-start justify-between gap-x-6 gap-y-1">
            <div class="min-w-0">
                <p class="text-sm font-medium">{{ describeEntry(entry) }}</p>
                <p v-if="showAccount" class="text-xs text-muted truncate">
                    {{ entry.account ? entry.account.name + " · " + entry.account.email : "Unknown account" }}
                </p>
                <p class="text-xs text-muted">
                    {{ entry.device || "Unknown device" }} · {{ networkLabel(entry.ip) }}
                </p>
            </div>
            <time class="text-xs text-muted tabular-nums" :datetime="entry.created_at">{{ when(entry.created_at) }}</time>
        </li>
    </ul>
    <nav v-if="entries.last_page > 1" class="flex items-center justify-between gap-3 pt-4" aria-label="Access log pages">
        <UButton label="Newer" icon="i-tabler-chevron-left" color="neutral" variant="outline" size="sm" :disabled="!entries.prev_page_url" @click="go(entries.prev_page_url)" />
        <span class="text-xs text-muted">Page {{ entries.current_page }} of {{ entries.last_page }}</span>
        <UButton label="Older" trailing-icon="i-tabler-chevron-right" color="neutral" variant="outline" size="sm" :disabled="!entries.next_page_url" @click="go(entries.next_page_url)" />
    </nav>
</template>
