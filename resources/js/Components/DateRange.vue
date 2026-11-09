<script setup>
import {
    computed,
    shallowRef,
    ref,
    watch,
    onMounted,
    onBeforeUnmount,
} from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { parseDate } from "@internationalized/date";
import { defineShortcuts } from "@nuxt/ui/composables";
import { queryUrl, reportFilters } from "../interface-query.mjs";
import { shiftPeriod, currentRange } from "../period-shift.mjs";
const props = defineProps({
    period: { type: Object, required: true },
    today: { type: String, required: true },
    timezone: { type: String, default: "UTC" },
    comparable: Boolean,
});
const page = usePage(),
    open = ref(false),
    small = ref(false);
const range = shallowRef({
    start: parseDate(props.period.from),
    end: parseDate(props.period.to),
});
const calendarMonth = shallowRef(
    parseDate(props.period.to).subtract({ months: 1 }),
);
const max = computed(() => parseDate(props.today));
const activePreset = computed(
    () =>
        new URL(page.url, "http://perysee.local").searchParams.get("period") ||
        "",
);
const comparing = computed(
    () =>
        new URL(page.url, "http://perysee.local").searchParams.get(
            "compare",
        ) === "previous",
);
const valid = computed(
    () =>
        range.value?.start &&
        range.value?.end &&
        range.value.start.compare(range.value.end) <= 0 &&
        range.value.end.compare(max.value) <= 0 &&
        range.value.end.compare(range.value.start.add({ days: 1095 })) <= 0,
);
const dateLabel = computed(
    () =>
        `${new Intl.DateTimeFormat("en", { month: "short", day: "numeric", timeZone: "UTC" }).format(parseDate(props.period.from).toDate("UTC"))} – ${new Intl.DateTimeFormat("en", { month: "short", day: "numeric", year: "numeric", timeZone: "UTC" }).format(parseDate(props.period.to).toDate("UTC"))}`,
);
watch(open, (value) => {
    if (value) {
        range.value = {
            start: parseDate(props.period.from),
            end: parseDate(props.period.to),
        };
        calendarMonth.value = parseDate(props.period.to).subtract({
            months: small.value ? 0 : 1,
        });
    }
});
const navigate = (values) => {
    open.value = false;
    router.get(
        queryUrl(page.props.currentUrl, {
            ...reportFilters(page.url),
            ...values,
        }),
        {},
        { preserveScroll: true },
    );
};
const shift = (direction) => {
    const next = shiftPeriod(
        props.period.from,
        props.period.to,
        direction,
        props.today,
    );
    if (next) navigate({ ...next, period: null });
};
defineShortcuts({ "[": () => shift(-1), "]": () => shift(1) });
const apply = () => {
    if (valid.value)
        navigate({
            from: range.value.start.toString(),
            to: range.value.end.toString(),
        });
};
let media;
const adapt = () => {
    small.value = media.matches;
};
onMounted(() => {
    media = matchMedia("(max-width:640px)");
    adapt();
    media.addEventListener("change", adapt);
});
onBeforeUnmount(() => media?.removeEventListener("change", adapt));
</script>
<template>
    <div class="flex flex-wrap items-center gap-2">
        <UButtonGroup class="hidden sm:flex"
            ><UButton
                v-for="preset in [
                    { key: 'today', label: 'Today' },
                    { key: '7d', label: '7 days' },
                    { key: '30d', label: '30 days' },
                ]"
                :key="preset.key"
                :label="preset.label"
                color="neutral"
                :variant="activePreset === preset.key ? 'solid' : 'outline'"
                size="sm"
                @click="navigate({ period: preset.key })"
        /></UButtonGroup>
        <UButton
            v-if="comparable"
            icon="i-tabler-arrows-diff"
            :label="small ? undefined : 'Compare'"
            aria-label="Compare with the previous period"
            color="neutral"
            :variant="comparing ? 'subtle' : 'outline'"
            size="sm"
            :aria-pressed="comparing"
            title="Compare with the previous period of the same length"
            @click="
                navigate({
                    ...currentRange(page.url, period),
                    compare: comparing ? null : 'previous',
                })
            "
        />
        <UPopover
            v-model:open="open"
            :content="{ align: 'end', sideOffset: 8 }"
        >
            <UButton
                color="neutral"
                variant="outline"
                icon="i-tabler-calendar"
                trailing-icon="i-tabler-chevron-down"
                :label="dateLabel"
                aria-label="Choose date range"
            />
            <template #content>
                <div
                    class="p-4 max-w-[calc(100vw-24px)] max-h-[var(--reka-popover-content-available-height)] overflow-y-auto"
                    data-date-range-panel
                >
                    <div class="flex justify-between items-start mb-4 gap-5">
                        <div>
                            <p class="font-medium text-sm text-highlighted">
                                Custom date range
                            </p>
                            <p class="text-xs text-muted mt-1">
                                {{ timezone }} · dates follow your site's
                                calendar
                            </p>
                        </div>
                        <UButton
                            icon="i-tabler-x"
                            color="neutral"
                            variant="ghost"
                            size="xs"
                            aria-label="Close calendar"
                            @click="open = false"
                        />
                    </div>
                    <div class="flex gap-2 mb-4">
                        <UButton
                            v-for="preset in [
                                { key: 'today', label: 'Today' },
                                { key: '7d', label: 'Last 7 days' },
                                { key: '30d', label: 'Last 30 days' },
                            ]"
                            :key="preset.key"
                            size="xs"
                            color="neutral"
                            variant="soft"
                            :label="preset.label"
                            @click="navigate({ period: preset.key })"
                        />
                    </div>
                    <UCalendar
                        v-model="range"
                        v-model:placeholder="calendarMonth"
                        range
                        :number-of-months="small ? 1 : 2"
                        :max-value="max"
                        :maximum-days="1096"
                        :week-starts-on="1"
                        fixed-weeks
                        aria-label="Select start and end dates"
                    />
                    <div class="border-t border-default mt-4 pt-4">
                        <UInputDate
                            v-model="range"
                            range
                            :max-value="max"
                            aria-label="Selected date range"
                            class="w-full"
                        />
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <UButton
                            color="neutral"
                            variant="ghost"
                            label="Cancel"
                            @click="open = false"
                        /><UButton
                            :disabled="!valid"
                            label="Apply range"
                            @click="apply"
                        />
                    </div>
                </div>
            </template>
        </UPopover>
    </div>
</template>
