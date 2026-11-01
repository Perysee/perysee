<script setup>
import { siteAvatar } from "../site-avatar.mjs";
import { ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
const props = defineProps(["websites", "search"]);
const page = usePage(),
    query = ref(props.search || "");
watch(
    () => props.search,
    (value) => {
        query.value = value || "";
    },
);
const search = () =>
    router.get(
        page.props.links["sites.index"],
        { q: query.value },
        { preserveState: true, preserveScroll: true },
    );
const columns = [
    { accessorKey: "name", header: "Website" },
    {
        accessorKey: "domain",
        header: "Domain",
        meta: {
            class: { th: "hidden lg:table-cell", td: "hidden lg:table-cell" },
        },
    },
    { accessorKey: "tracking_mode", header: "Measurement" },
    { id: "role", header: "Access" },
    { id: "actions", header: "" },
];
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">Your workspace</p>
            <h1>Your sites</h1>
            <p class="text-sm text-muted">
                One place for every website you measure.
            </p>
        </div>
        <UButton
            :to="page.props.links['sites.create']"
            icon="i-tabler-plus"
            label="Add site"
        />
    </div>
    <div class="report-panel">
        <div
            class="flex items-center justify-between gap-4 p-5 border-b border-default"
        >
            <form class="flex gap-2 min-w-0" @submit.prevent="search">
                <UInput
                    v-model="query"
                    icon="i-tabler-search"
                    placeholder="Search by name or domain"
                    aria-label="Search sites"
                    class="w-full sm:w-72"
                /><UButton
                    type="submit"
                    label="Search"
                    variant="outline"
                    color="neutral"
                /><UButton
                    v-if="query"
                    label="Clear"
                    variant="ghost"
                    color="neutral"
                    @click="
                        query = '';
                        search();
                    "
                />
            </form>
            <span class="text-xs text-muted hidden sm:block"
                >{{ websites.total }} sites</span
            >
        </div>
        <UTable
            v-if="websites.data.length"
            :data="websites.data"
            :columns="columns"
            :ui="{ th: 'text-xs font-normal text-muted', td: 'text-sm py-5' }"
        >
            <template #name-cell="{ row }"
                ><div class="flex gap-3 items-center min-w-0">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center border border-default"
                        ><UAvatar
                            v-bind="siteAvatar(row.original.favicon_url, row.original.name)"
                            size="xs"
                    /></span>
                    <div class="min-w-0">
                        <UButton
                            :to="row.original.url"
                            :label="row.original.name"
                            color="neutral"
                            variant="link"
                            class="p-0 truncate font-medium"
                        />
                        <p class="text-xs text-muted lg:hidden truncate mt-1">
                            {{ row.original.domain }}
                        </p>
                        <UBadge
                            v-if="row.original.is_demo"
                            color="warning"
                            variant="subtle"
                            size="xs"
                            class="mt-1"
                            >Demonstration data</UBadge
                        >
                    </div>
                </div></template
            >
            <template #tracking_mode-cell="{ row }"
                ><span>{{
                    row.original.tracking_mode === "sessions"
                        ? "Sessions"
                        : "Pageviews"
                }}</span>
                <p class="text-xs text-muted mt-1">
                    {{
                        row.original.consent_required
                            ? "Consent required"
                            : "Publisher assessed"
                    }}
                </p></template
            >
            <template #role-cell="{ row }"
                ><UBadge variant="subtle" color="neutral" class="capitalize">{{
                    row.original.pivot?.role
                }}</UBadge></template
            >
            <template #actions-cell="{ row }"
                ><UButton
                    :to="
                        row.original.can_update
                            ? row.original.edit_url
                            : row.original.url
                    "
                    :icon="
                        row.original.can_update
                            ? 'i-tabler-pencil'
                            : 'i-tabler-arrow-up-right'
                    "
                    color="neutral"
                    variant="ghost"
                    :aria-label="
                        (row.original.can_update ? 'Edit ' : 'Open ') +
                        row.original.name
                    "
            /></template>
        </UTable>
        <UEmpty
            v-else
            icon="i-tabler-world"
            :title="query ? 'No matching sites' : 'Start with your first site'"
            :description="
                query
                    ? 'Try another name or domain.'
                    : 'Add a domain and connect Lueur to see real traffic.'
            "
            :actions="
                query
                    ? []
                    : [
                          {
                              label: 'Add your first site',
                              to: page.props.links['sites.create'],
                              icon: 'i-tabler-plus',
                          },
                      ]
            "
            class="py-14"
        />
        <div
            v-if="websites.last_page > 1"
            class="p-4 border-t border-default flex justify-center"
        >
            <UPagination
                :page="websites.current_page"
                :total="websites.total"
                :items-per-page="websites.per_page"
                @update:page="
                    (number) =>
                        router.get(
                            page.props.links['sites.index'],
                            { q: query, page: number },
                            { preserveScroll: true },
                        )
                "
            />
        </div>
    </div>
</template>
