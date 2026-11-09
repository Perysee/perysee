<script setup>
import { ref, computed } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
const props = defineProps(["website", "timezones", "currencies", "siteLinks", "canUpdate"]);
const page = usePage(),
    deleting = ref(false);
const form = useForm({
    name: props.website.name || "",
    domain: props.website.domain || "",
    allowed_domains: (props.website.allowed_domains || []).join("\n"),
    timezone: props.website.timezone || "UTC",
    currency: props.website.currency || "",
    excluded_paths: (props.website.excluded_paths || []).join("\n"),
    tracking_mode: props.website.tracking_mode || "pageviews",
    consent_required: props.website.consent_required ?? true,
});
const removal = useForm({ confirmation: "" });
const editing = computed(() => !!props.website.id);
// An empty currency is sent as an empty string, which the server stores as "not set".
form.transform((data) => ({ ...data, currency: data.currency || "" }));
const submit = () =>
    editing.value
        ? form.put(props.siteLinks.update, { preserveScroll: true })
        : form.post(page.props.links["sites.store"]);
</script>
<template>
    <div class="page-heading">
        <div>
            <h1>{{ editing ? "Site settings" : "Add site" }}</h1>
            <p class="text-sm text-muted">
                Your website, measurement and privacy settings.
            </p>
        </div>
        <UButton
            :to="editing ? siteLinks.show : page.props.links['sites.index']"
            icon="i-tabler-arrow-left"
            color="neutral"
            variant="ghost"
            label="Back"
        />
    </div>
    <UCard
        ><UForm :state="form" class="space-y-6" @submit="submit"
            ><div class="form-grid">
                <UFormField
                    label="Site name"
                    name="name"
                    :error="form.errors.name"
                    required
                    ><UInput
                        v-model="form.name"
                        maxlength="150"
                        class="w-full"
                        autofocus /></UFormField
                ><UFormField
                    label="Primary domain"
                    name="domain"
                    :error="form.errors.domain"
                    help="Domain only, without https:// or a path."
                    required
                    ><UInput
                        v-model="form.domain"
                        placeholder="example.com"
                        maxlength="253"
                        class="w-full"
                /></UFormField>
            </div>
            <div class="form-grid">
                <UFormField
                    label="Additional allowed domains"
                    name="allowed_domains"
                    :error="form.errors.allowed_domains"
                    help="One domain per line, up to 20. The primary domain is included automatically."
                    ><UTextarea
                        v-model="form.allowed_domains"
                        :rows="4"
                        class="w-full" /></UFormField
                ><UFormField
                    label="Excluded paths"
                    name="excluded_paths"
                    :error="form.errors.excluded_paths"
                    help="One per line. A final * matches a prefix: /admin/*."
                    ><UTextarea
                        v-model="form.excluded_paths"
                        :rows="4"
                        class="w-full"
                /></UFormField>
            </div>
            <UFormField
                label="Reporting timezone"
                name="timezone"
                :error="form.errors.timezone"
                :description="
                    website.first_collected_at
                        ? 'Reporting timezone is locked after the first collection.'
                        : 'Defines reporting days. Stored dates remain UTC.'
                "
                required
                ><USelectMenu
                    v-model="form.timezone"
                    :items="timezones"
                    :disabled="!!website.first_collected_at"
                    class="w-full"
                    aria-label="Reporting timezone" /></UFormField
            ><UFormField
                label="Currency of event values"
                name="currency"
                :error="form.errors.currency"
                description="Label shown next to the value of conversions. Perysee never converts between currencies."
                ><USelectMenu
                    v-model="form.currency"
                    :items="currencies"
                    placeholder="Not set"
                    clear
                    class="w-full"
                    aria-label="Currency of event values" /></UFormField
            ><USeparator /><UFormField
                label="Measurement"
                name="tracking_mode"
                :error="form.errors.tracking_mode"
                description="Sessions expire after 30 minutes of inactivity, at most 24 hours. They are not unique people."
                ><USelect
                    v-model="form.tracking_mode"
                    :items="[
                        {
                            value: 'pageviews',
                            label: 'Pageviews · no identifier',
                        },
                        {
                            value: 'sessions',
                            label: 'Pageviews and sessions · first-party cookie',
                        },
                    ]"
                    class="w-full"
                    aria-label="Measurement" /></UFormField
            ><UFormField
                label="Consent policy"
                name="consent_required"
                :error="form.errors.consent_required"
                ><USwitch
                    v-model="form.consent_required"
                    label="Wait for consent before measurement"
                    description="Recommended. No cookies does not automatically mean no consent." /></UFormField
            ><UAlert
                v-if="!form.consent_required"
                color="warning"
                variant="subtle"
                icon="i-tabler-shield-lock"
                title="Publisher assessment"
                description="You are choosing automatic measurement. Assess your audience, purposes and applicable rules. Country lookup does not decide whether consent is required." />
            <p class="data-note">
                Update your tracking snippet after changing measurement
                settings.
            </p>
            <UAlert
                v-if="Object.keys(form.errors).some((key) => key.includes('.'))"
                color="error"
                variant="subtle"
                :description="Object.values(form.errors).join(' ')" />
            <div class="flex gap-3">
                <UButton
                    type="submit"
                    :loading="form.processing"
                    :label="editing ? 'Save changes' : 'Create site'"
                    icon="i-tabler-check"
                /><UButton
                    :to="page.props.links['sites.index']"
                    label="Cancel"
                    variant="ghost"
                    color="neutral"
                /></div></UForm
    ></UCard>
    <UCard v-if="editing && canUpdate" class="mt-6"
        ><div class="flex flex-wrap justify-between gap-4 items-center">
            <div>
                <h2 class="text-sm font-medium">Delete site and data</h2>
                <p class="data-note mt-1">
                    Collection stops immediately. Scheduled maintenance removes
                    the data in batches.
                </p>
            </div>
            <UModal
                v-model:open="deleting"
                title="Delete site and data"
                description="This action cannot be undone. Confirm the domain to stop collection and schedule permanent deletion."
                ><UButton
                    label="Delete site"
                    color="error"
                    variant="outline"
                    icon="i-tabler-trash" /><template #body
                    ><UForm
                        :state="removal"
                        class="space-y-5"
                        @submit="removal.delete(siteLinks.destroy)"
                        ><UFormField
                            :label="'Type ' + website.domain + ' to confirm'"
                            name="confirmation"
                            :error="removal.errors.confirmation"
                            ><UInput
                                v-model="removal.confirmation"
                                class="w-full"
                                autocomplete="off"
                        /></UFormField>
                        <div class="flex justify-end gap-2">
                            <UButton
                                label="Cancel"
                                color="neutral"
                                variant="ghost"
                                @click="deleting = false"
                            /><UButton
                                type="submit"
                                label="Delete site"
                                color="error"
                                :disabled="
                                    removal.confirmation !== website.domain
                                "
                                :loading="removal.processing"
                            /></div></UForm></template
            ></UModal></div
    ></UCard>
</template>
