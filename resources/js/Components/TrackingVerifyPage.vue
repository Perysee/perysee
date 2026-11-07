<script setup>
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { classifyProbe, classifyScript, compareOrigins, formatVolume, probeRequest } from "../tracker-check.mjs";
const props = defineProps(["website", "siteLinks", "canUpdate", "snippet", "check", "collectorRoot"]);
const page = usePage();
const copied = ref(false),
    copyFailed = ref(false),
    refreshing = ref(false),
    testing = ref(false),
    probe = ref(null),
    script = ref(null),
    tokenFailed = ref(false);
const originAdvice = computed(() => compareOrigins(window.location.origin, props.collectorRoot));
const sameOrigin = computed(() => !originAdvice.value && new URL(props.collectorRoot).origin === window.location.origin);
const states = {
    receiving: { color: "success", label: "Receiving events" },
    silent: { color: "warning", label: "Silent" },
    waiting: { color: "neutral", label: "Waiting for the first event" },
};
const levelColor = { success: "success", warning: "warning", error: "error", info: "info" };
const levelIcon = {
    success: "i-tabler-circle-check",
    warning: "i-tabler-alert-triangle",
    error: "i-tabler-alert-circle",
    info: "i-tabler-info-circle",
};
const causeLabels = {
    domain_not_allowed: "Domain not allowed",
    unknown_site: "Unknown site key",
    consent_missing: "Consent missing",
    mode_mismatch: "Measurement mode differs",
    invalid_payload: "Invalid send",
    quota: "Request limit reached",
    path_excluded: "Excluded page",
};
const when = (iso) =>
    iso
        ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short", timeZone: props.website.timezone }).format(new Date(iso))
        : null;
const ago = (iso) => {
    if (!iso) return null;
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
    for (const [unit, size] of [["day", 86400], ["hour", 3600], ["minute", 60]])
        if (Math.abs(seconds) >= size) return format.format(Math.round(seconds / size), unit);
    return format.format(0, "second");
};
async function copy() {
    try {
        await navigator.clipboard.writeText(props.snippet);
        copied.value = true;
        copyFailed.value = false;
    } catch {
        copied.value = false;
        copyFailed.value = true;
    }
}
function refresh() {
    refreshing.value = true;
    router.reload({ only: ["check"], onFinish: () => (refreshing.value = false) });
}
const csrf = () => decodeURIComponent(document.cookie.split("; ").find((part) => part.startsWith("XSRF-TOKEN="))?.slice(11) ?? "");
async function runTest() {
    testing.value = true;
    probe.value = script.value = null;
    tokenFailed.value = false;
    try {
        let issued;
        try {
            const response = await fetch(props.siteLinks.probe, { method: "POST", headers: { Accept: "application/json", "X-XSRF-TOKEN": csrf() }, credentials: "same-origin" });
            if (!response.ok) throw new Error("token");
            issued = await response.json();
        } catch {
            tokenFailed.value = true;
            return;
        }
        const { url, init } = probeRequest(issued, props.collectorRoot);
        const [probed, loaded] = await Promise.all([
            fetch(url, init).then((response) => ({ status: response.status, echoed: response.headers.get("X-Perysee-Probe") === issued.token, contentType: response.headers.get("Content-Type") ?? "" })).catch(() => ({ error: true })),
            fetch(props.collectorRoot.replace(/\/+$/, "") + "/lueur.js", { mode: "cors", credentials: "omit", cache: "no-store" })
                .then(async (response) => ({ status: response.status, contentType: response.headers.get("Content-Type") ?? "", body: (await response.text()).slice(0, 400) }))
                .catch(() => ({ error: true })),
        ]);
        probe.value = classifyProbe(probed);
        script.value = classifyScript(loaded);
        refresh();
    } finally {
        testing.value = false;
    }
}
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">{{ website.domain }}</p>
            <h1>Verify the installation</h1>
            <p class="text-sm text-muted">Find out why events do or do not arrive.</p>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
            <UBadge :color="states[check.state].color" variant="subtle">{{ states[check.state].label }}</UBadge>
            <UButton :to="siteLinks.tracking" icon="i-tabler-code" label="Tracking setup" color="neutral" variant="outline" />
        </div>
    </div>

    <div class="grid sm:grid-cols-3 gap-4">
        <UCard>
            <p class="text-xs text-muted">Last event received</p>
            <template v-if="check.last_event">
                <p class="mt-2 text-lg font-medium" data-test="last-event">{{ ago(check.last_event.at) }}</p>
                <p class="text-xs text-muted mt-1">{{ when(check.last_event.at) }}</p>
                <p class="text-xs mt-1 font-mono break-all">{{ check.last_event.type === "event" ? "Custom event" : "Pageview" }} · {{ check.last_event.path }}</p>
            </template>
            <template v-else>
                <p class="mt-2 text-lg font-medium" data-test="last-event">None yet</p>
                <p class="text-xs text-muted mt-1">
                    {{ check.first_collected_at ? "Older events were removed by the retention setting." : "No event has ever been received." }}
                </p>
            </template>
        </UCard>
        <UCard>
            <p class="text-xs text-muted">Events in the last 24 hours</p>
            <p class="mt-2 text-lg font-medium" data-test="volume">{{ formatVolume(check.events_24h) }}</p>
            <p class="text-xs text-muted mt-1">{{ check.events_24h.capped ? "Counting stops at 10,000." : "Pageviews and custom events." }}</p>
        </UCard>
        <UCard>
            <p class="text-xs text-muted">Last installation test</p>
            <p class="mt-2 text-lg font-medium" data-test="last-probe">{{ check.probe ? ago(check.probe.at) : "Never run" }}</p>
            <p class="text-xs text-muted mt-1">{{ check.probe ? when(check.probe.at) : "Use “Test now” below." }}</p>
        </UCard>
    </div>

    <section class="mt-6" aria-labelledby="diagnosis-title">
        <h2 id="diagnosis-title" class="text-sm font-medium mb-3">What we found</h2>
        <div class="space-y-3">
            <UAlert
                v-for="item in check.diagnoses"
                :key="item.code"
                :color="levelColor[item.level]"
                variant="subtle"
                :icon="levelIcon[item.level]"
                :title="item.title"
            >
                <template #description>
                    <p>{{ item.message }}</p>
                    <p v-if="item.hits" class="data-note mt-1">At least {{ item.hits }} in the last 24 hours. Counts are a floor: bursts of refusals are only partly recorded.</p>
                    <p v-if="item.action" class="mt-2"><strong class="font-medium">What to do.</strong> {{ item.action }}</p>
                </template>
            </UAlert>
        </div>
    </section>

    <UCard class="mt-6">
        <template #header>
            <div class="flex justify-between gap-4 items-center">
                <h2 class="text-sm font-medium">Test now</h2>
                <UButton icon="i-tabler-refresh" label="Refresh" color="neutral" variant="ghost" size="sm" :loading="refreshing" @click="refresh" />
            </div>
        </template>
        <p class="text-sm text-muted">
            Sends a signed test to the real collector address and loads the tracking script from the address in your snippet. Nothing is added to your statistics.
        </p>
        <p class="data-note mt-2">
            The test runs from this administration, so it proves the collector, the site key and the database. It cannot prove the allowed domains, the consent banner or the page cache of your site: only a real event does.
        </p>
        <UButton v-if="canUpdate" class="mt-4" icon="i-tabler-player-play" label="Test now" :loading="testing" :disabled="testing" @click="runTest" />
        <p v-else class="data-note mt-4">Only a site owner can run the test.</p>
        <div v-if="testing || probe || tokenFailed" class="mt-4 space-y-3" role="status" aria-live="polite">
            <p v-if="testing" class="text-sm text-muted">Testing…</p>
            <UAlert v-if="tokenFailed" color="error" variant="subtle" icon="i-tabler-alert-circle" title="The test could not start" description="Reload the page and try again. Tests are limited to a few per minute." />
            <template v-if="probe">
                <UAlert :color="levelColor[probe.level]" variant="subtle" :icon="levelIcon[probe.level]" :title="'Collector: ' + probe.title">
                    <template #description><p>{{ probe.message }}</p><p v-if="probe.action" class="mt-1">{{ probe.action }}</p></template>
                </UAlert>
                <UAlert :color="levelColor[script.level]" variant="subtle" :icon="levelIcon[script.level]" :title="'Script: ' + script.title">
                    <template #description><p>{{ script.message }}</p><p v-if="script.action" class="mt-1">{{ script.action }}</p><p v-if="script.outcome === 'ok' && sameOrigin" class="data-note mt-1">Loaded from the same address as this page, so permission for other sites to load it was not exercised.</p></template>
                </UAlert>
            </template>
        </div>
        <UAlert v-if="originAdvice" class="mt-4" color="warning" variant="subtle" icon="i-tabler-world-www" title="This page and the snippet use different addresses">
            <template #description><p>{{ originAdvice.message }}</p><p class="mt-1">{{ originAdvice.action }}</p></template>
        </UAlert>
    </UCard>

    <UCard v-if="check.rejections.length" class="mt-6">
        <template #header><h2 class="text-sm font-medium">Refused in the last 24 hours</h2></template>
        <ul class="divide-y divide-default text-sm">
            <li v-for="item in check.rejections" :key="item.cause" class="py-3 flex flex-wrap justify-between gap-2">
                <span>{{ causeLabels[item.cause] ?? item.cause }}<span v-if="item.host" class="text-muted"> · from {{ item.host }}</span></span>
                <span class="text-muted"><template v-if="item.hits !== null">at least {{ item.hits }}, </template><template v-else>possibly this site, </template>last {{ ago(item.last_at) }}</span>
            </li>
        </ul>
        <p class="data-note mt-3">Only the cause, the hour, a count and the origin host are kept, for seven days. No address, browser or page is stored.</p>
    </UCard>

    <UCard class="mt-6">
        <template #header>
            <div class="flex justify-between gap-4 items-center">
                <h2 class="text-sm font-medium">Your snippet</h2>
                <UButton :icon="copied ? 'i-tabler-check' : 'i-tabler-copy'" :label="copied ? 'Copied' : 'Copy snippet'" color="neutral" variant="ghost" size="sm" @click="copy" />
            </div>
        </template>
        <p v-if="copyFailed" role="alert" class="text-sm text-warning mb-3">Copy unavailable. Select the snippet below and copy it manually.</p>
        <pre class="code-block select-all" tabindex="0" aria-label="Tracking snippet"><code>{{ snippet }}</code></pre>
        <div class="grid md:grid-cols-3 gap-4 mt-5 text-xs">
            <div>
                <p class="font-medium">HTML</p>
                <p class="text-muted mt-1">Paste it in the head of every page you want to measure, then clear the cache of the page and of your CDN.</p>
                <UButton :href="page.props.docsUrl + '/integrations/html'" external label="HTML guide" color="neutral" variant="link" size="xs" class="p-0 mt-1" />
            </div>
            <div>
                <p class="font-medium">WordPress</p>
                <p class="text-muted mt-1">Add it with a header scripts plugin or your theme, and empty the cache plugin afterwards.</p>
                <UButton :href="page.props.docsUrl + '/integrations/wordpress'" external label="WordPress guide" color="neutral" variant="link" size="xs" class="p-0 mt-1" />
            </div>
            <div>
                <p class="font-medium">Nuxt</p>
                <p class="text-muted mt-1">Declare it in the head of the app, then rebuild: a generated site keeps the old head until then.</p>
                <UButton :href="page.props.docsUrl + '/integrations/nuxt'" external label="Nuxt guide" color="neutral" variant="link" size="xs" class="p-0 mt-1" />
            </div>
        </div>
    </UCard>
</template>
