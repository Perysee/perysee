<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
const props = defineProps(["profile", "profiles", "categories", "findings", "counts", "docsPath", "healthUrl"]);
const page = usePage();
// The label always says the status in words: the colour and the icon only back it up.
const STATUS = {
    ok: { label: "OK", icon: "i-tabler-circle-check", color: "success" },
    attention: { label: "Attention", icon: "i-tabler-alert-triangle", color: "warning" },
    problem: { label: "Problem", icon: "i-tabler-alert-octagon", color: "error" },
    unavailable: { label: "Unavailable", icon: "i-tabler-help-circle", color: "neutral" },
};
const status = (finding) => STATUS[finding.status] ?? STATUS.unavailable;
// Known categories first, in their order; a category nobody named (a later check) follows under its own identifier.
const sections = computed(() => {
    const known = props.categories.map((category) => ({ ...category, findings: props.findings.filter((finding) => finding.category === category.id) }));
    const ids = new Set(props.categories.map((category) => category.id));
    const other = [...new Set(props.findings.map((finding) => finding.category).filter((id) => !ids.has(id)))].map((id) => ({ id, label: id, findings: props.findings.filter((finding) => finding.category === id) }));
    return [...known, ...other].filter((section) => section.findings.length);
});
const docsUrl = (finding) => page.props.docsUrl + props.docsPath + "#" + finding.slug;
</script>
<template>
    <div class="page-heading">
        <div>
            <h1>Configuration audit</h1>
            <p class="text-sm text-muted">What the current settings imply, read when this page opens. Nothing here changes a setting.</p>
        </div>
        <UButton :to="healthUrl" label="Instance health" icon="i-tabler-activity" color="neutral" variant="outline" size="sm" />
    </div>
    <UCard class="mb-6">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
            <nav aria-label="Audit profile" class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-muted">Profile</span>
                <UButton
                    v-for="candidate in profiles"
                    :key="candidate.id"
                    :to="candidate.url"
                    :label="candidate.label"
                    :aria-current="candidate.active ? 'true' : undefined"
                    :color="candidate.active ? 'primary' : 'neutral'"
                    :variant="candidate.active ? 'solid' : 'outline'"
                    size="sm"
                />
            </nav>
            <p class="text-sm" role="status">
                <strong>{{ counts.ok }}</strong> OK · <strong>{{ counts.attention }}</strong> attention · <strong>{{ counts.problem }}</strong> problem<template v-if="counts.unavailable"> · <strong>{{ counts.unavailable }}</strong> unavailable</template>
            </p>
        </div>
        <p class="data-note mt-4">{{ profile.note }} The thresholds of a profile are indicative: they describe a configuration, they do not replace legal advice, and an audit without findings is not a statement of compliance.</p>
    </UCard>
    <UCard v-for="section in sections" :key="section.id" class="mb-6" :aria-labelledby="'audit-' + section.id">
        <template #header>
            <h2 :id="'audit-' + section.id" class="text-sm font-medium">{{ section.label }}</h2>
        </template>
        <ul class="divide-y divide-default">
            <li v-for="finding in section.findings" :id="finding.slug" :key="finding.id" class="py-4 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-center gap-3">
                    <UBadge :color="status(finding).color" :icon="status(finding).icon" :label="status(finding).label" variant="subtle" />
                    <h3 class="text-sm font-medium">{{ finding.title }}</h3>
                </div>
                <dl class="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt class="text-muted">Value read</dt>
                    <dd>{{ finding.value }}</dd>
                    <template v-if="finding.threshold">
                        <dt class="text-muted">Threshold of this profile</dt>
                        <dd>{{ finding.threshold }}</dd>
                    </template>
                </dl>
                <p class="mt-2 text-sm text-muted">{{ finding.explanation }}</p>
                <div class="mt-1 flex flex-wrap gap-x-4">
                    <UButton v-if="finding.setting_url" :to="finding.setting_url" label="Where to change it" color="neutral" variant="link" size="xs" class="p-0" />
                    <UButton :href="docsUrl(finding)" external label="Documentation" color="neutral" variant="link" size="xs" class="p-0" />
                </div>
            </li>
        </ul>
    </UCard>
</template>
