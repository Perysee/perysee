<script setup>
import { ref } from "vue";
import { usePage } from "@inertiajs/vue3";
const props = defineProps(["website", "siteLinks", "canUpdate", "snippet"]);
const page = usePage(),
    copied = ref(false), copyFailed = ref(false);
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
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">{{ website.domain }}</p>
            <h1>Tracking setup</h1>
            <p class="text-sm text-muted">Connect Lueur to your website.</p>
        </div>
        <UButton
            v-if="canUpdate"
            :to="siteLinks.edit"
            icon="i-tabler-settings"
            label="Site settings"
            color="neutral"
            variant="outline"
        />
    </div>
    <UCard
        ><template #header
            ><div class="flex justify-between gap-4 items-center">
                <h2 class="text-sm font-medium">Install Lueur</h2>
                <UButton
                    :icon="copied ? 'i-tabler-check' : 'i-tabler-copy'"
                    :label="copied ? 'Copied' : 'Copy snippet'"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    @click="copy"
                /></div
        ></template>
        <p class="text-sm text-muted mb-5">
            Add this script to the pages you want to measure.
        </p>
        <p v-if="copyFailed" role="alert" class="text-sm text-warning mb-3">Copy unavailable. Select the snippet below and copy it manually.</p><pre class="code-block select-all" tabindex="0" aria-label="Tracking snippet"><code>{{snippet}}</code></pre>
        <UAlert
            class="mt-5"
            color="neutral"
            variant="soft"
            icon="i-tabler-shield-lock"
            :title="
                website.consent_required
                    ? 'Wait for consent'
                    : 'Publisher assessment'
            "
            :description="
                website.consent_required
                    ? 'Connect your consent manager. No events or session cookies are created before activation. Load Lueur itself after permission for strict gating.'
                    : 'Automatic measurement follows your assessment that consent is not required. Keep an objection mechanism available.'
            " />
        <pre
            v-if="website.consent_required"
            class="code-block mt-5"
        ><code>window.lueur = window.lueur || [];
window.lueur.push(['consent', true]);</code></pre>
        <div class="flex flex-wrap gap-2 mt-5">
            <UButton
                v-if="siteLinks.demo"
                :href="siteLinks.demo"
                external
                label="Open local test site"
                trailing-icon="i-tabler-arrow-up-right"
                color="neutral"
                variant="outline"
            /><UButton
                :href="page.props.docsUrl + '/integrations'"
                external
                icon="i-tabler-book-2"
                label="Integration guides"
                color="neutral"
                variant="ghost"
            /></div
    ></UCard>
    <div class="grid lg:grid-cols-2 gap-6 mt-6">
        <UCard
            ><template #header
                ><h2 class="text-sm font-medium">Custom events</h2></template
            >
            <p class="data-note mb-4">
                Names only. No personal information or additional properties.
                Events obey consent and privacy signals.
            </p>
            <pre class="code-block"><code>window.lueur = window.lueur || [];
window.lueur.push(['track', 'signup']);</code></pre>
            <p class="data-note mt-4">
                Withdraw with lueur.consent(false). Exclude administrators using
                window.peryseeExclude = true before Lueur loads.
            </p></UCard
        ><UCard
            ><template #header
                ><h2 class="text-sm font-medium">
                    Site configuration
                </h2></template
            >
            <dl class="space-y-4 text-xs">
                <div>
                    <dt class="text-muted">Public site identifier</dt>
                    <dd class="font-mono mt-1 break-all">
                        {{ website.public_id }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted">Measurement</dt>
                    <dd class="mt-1">
                        {{
                            website.tracking_mode === "sessions"
                                ? "Pageviews and sessions"
                                : "Pageviews without an identifier"
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted">Reporting timezone</dt>
                    <dd class="mt-1">{{ website.timezone }}</dd>
                </div>
                <div>
                    <dt class="text-muted">Allowed domains</dt>
                    <dd class="mt-1">
                        {{ website.allowed_domains.join(", ") }}
                    </dd>
                </div>
            </dl>
            <p class="data-note mt-5">
                Reports update every five minutes when the scheduler is running.
            </p></UCard
        >
    </div>
</template>
