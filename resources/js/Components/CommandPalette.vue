<script setup>
import { computed, ref, watch, nextTick } from "vue";
import { router } from "@inertiajs/vue3";
const props = defineProps({
    groups: { type: Array, default: () => [] },
});
const open = defineModel("open", { type: Boolean, default: false });
const fuse = {
    fuseOptions: {
        ignoreLocation: true,
        threshold: 0.2,
        keys: ["label", "suffix", "keywords"],
    },
    resultLimit: 40,
    matchAllWhenSearchEmpty: true,
};
// Every row must render as the same element: mixing links and buttons makes Vue swap the
// node under the highlighted row when the list is filtered, which loses the highlight.
const go = ({ to, run, ...item }) => ({
    ...item,
    class: item.active ? ["palette-current", item.class] : item.class,
    onSelect: () => {
        open.value = false;
        if (to) router.visit(to);
        else run?.();
    },
});
const searchTerm = ref("");
const panel = ref(null);
const announcement = ref("");
watch([searchTerm, open], async () => {
    announcement.value = "";
    await nextTick();
    setTimeout(() => {
        if (!open.value || !panel.value) return;
        const count = panel.value.querySelectorAll('[role="option"]').length;
        announcement.value = count
            ? `${count} result${count > 1 ? "s" : ""} available. Use the up and down arrow keys, then Enter.`
            : "No results.";
    }, 120);
});
const prepared = computed(() =>
    props.groups
        .filter((group) => group.items.length)
        .map((group) => ({ ...group, items: group.items.map(go) })),
);
</script>
<template>
    <UModal
        v-model:open="open"
        title="Command palette"
        description="Search sites, report sections, periods and actions."
        :ui="{ overlay: 'z-50', content: 'z-50 sm:max-w-xl' }"
    >
        <template #content>
            <div ref="panel">
            <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
            <UCommandPalette
                v-model:search-term="searchTerm"
                loop
                :groups="prepared"
                :fuse="fuse"
                placeholder="Search a site, report, period, AI source…"
                class="h-[70vh] sm:h-[26rem]"
                :close="{ onClick: () => (open = false) }"
            >
                <template #empty="{ searchTerm: term }">
                    <div class="px-4 py-8 text-center text-sm text-muted" role="status">
                        <UIcon name="i-tabler-search-off" class="size-6 mb-2" />
                        <p>
                            Nothing matches
                            <strong class="text-highlighted">“{{ term }}”</strong>.
                        </p>
                        <p class="text-xs mt-1">
                            Try a site name, a domain, “countries”, “7 days” or “ChatGPT”.
                        </p>
                    </div>
                </template>
                <template #footer>
                    <p class="px-3 py-2 text-xs text-muted flex flex-wrap gap-x-4 gap-y-1">
                        <span><UKbd value="↑" /> <UKbd value="↓" /> navigate</span>
                        <span><UKbd value="enter" /> open</span>
                        <span><UKbd value="/" /> filter</span>
                        <span><UKbd value="[" /> <UKbd value="]" /> period</span>
                        <span><UKbd value="g" /> then <UKbd value="s" /> sites</span>
                    </p>
                </template>
            </UCommandPalette>
            </div>
        </template>
    </UModal>
</template>
