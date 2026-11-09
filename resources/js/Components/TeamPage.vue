<script setup>
import { computed, ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
const props = defineProps([
    "website",
    "siteLinks",
    "canManage",
    "members",
    "invitations",
    "inviteUrl",
    "leaveUrl",
    "invitationLink",
    "invitationEmail",
    "errors",
]);
const roles = [
    { value: "viewer", label: "Viewer" },
    { value: "owner", label: "Owner" },
];
const invite = useForm({ email: "", role: "viewer" });
const copied = ref(false);
const copyFailed = ref(false);
const owners = computed(() => props.members.filter((member) => member.role === "owner").length);
const sendInvite = () => {
    if (invite.role === "owner" && !confirm("An owner can invite people, change roles and delete the site. Invite as owner?")) return;
    invite.post(props.inviteUrl, { preserveScroll: true, onSuccess: () => invite.reset("email") });
};
const changeRole = (member, role) => {
    if (role === "owner" && !confirm(`Make ${member.name} an owner of ${props.website.name}?`)) return;
    router.put(member.update_url, { role }, { preserveScroll: true });
};
const remove = (member) => {
    if (confirm(`Remove ${member.name} from ${props.website.name}?`)) router.delete(member.remove_url, { preserveScroll: true });
};
const leave = () => {
    if (confirm(`Leave ${props.website.name}? You will lose access to its reports.`)) router.delete(props.leaveUrl);
};
const resend = (invitation) => router.post(invitation.resend_url, {}, { preserveScroll: true });
const revoke = (invitation) => {
    if (confirm(`Revoke the invitation sent to ${invitation.email}?`)) router.delete(invitation.revoke_url, { preserveScroll: true });
};
async function copy() {
    try {
        await navigator.clipboard.writeText(props.invitationLink);
        copied.value = true;
        copyFailed.value = false;
    } catch {
        copied.value = false;
        copyFailed.value = true;
    }
}
const day = (iso) => new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" });
</script>
<template>
    <div class="settings-page">
    <div class="page-heading">
        <div>
            <h1>Team</h1>
            <p class="text-sm text-muted">Who can see the reports of {{ website.domain }}.</p>
        </div>
    </div>
    <UAlert v-if="errors?.member" color="error" variant="soft" :description="errors.member" class="mb-5" role="alert" />
    <UAlert
        v-if="invitationLink"
        class="mb-5"
        color="success"
        variant="soft"
        icon="i-tabler-link"
        :title="'Invitation for ' + invitationEmail"
        description="Send this link to the person. It works once, expires in 7 days and is shown only now."
    >
        <template #actions>
            <UButton :icon="copied ? 'i-tabler-check' : 'i-tabler-copy'" :label="copied ? 'Copied' : 'Copy link'" color="neutral" variant="outline" size="sm" @click="copy" />
        </template>
        <template #description>
            <p class="mb-2">Send this link to the person. It works once, expires in 7 days and is shown only now.</p>
            <p v-if="copyFailed" role="alert" class="text-warning mb-2">Copy unavailable. Select the link and copy it manually.</p>
            <code class="block break-all select-all text-xs" tabindex="0">{{ invitationLink }}</code>
        </template>
    </UAlert>
    <section class="settings-section" aria-labelledby="team-members">
        <div class="settings-section-head">
            <h2 id="team-members">Members</h2>
            <span class="tabular-nums">{{ members.length }} {{ members.length === 1 ? "person" : "people" }}</span>
        </div>
        <ul class="settings-rows">
            <li v-for="member in members" :key="member.id">
                <div class="flex min-w-0 items-center gap-3">
                    <UAvatar :alt="member.name" size="md" />
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate">{{ member.name }}<span v-if="member.you" class="text-muted font-normal"> (you)</span></p>
                        <p class="text-xs text-muted truncate">{{ member.email }}</p>
                    </div>
                </div>
                <div v-if="canManage" class="flex items-center gap-2">
                    <USelect
                        :model-value="member.role"
                        :items="roles"
                        :aria-label="'Role of ' + member.name"
                        :disabled="member.role === 'owner' && owners < 2"
                        size="sm"
                        @update:model-value="(role) => changeRole(member, role)"
                    />
                    <UButton label="Remove" :aria-label="'Remove ' + member.name" color="neutral" variant="ghost" size="sm" :disabled="member.role === 'owner' && owners < 2" @click="remove(member)" />
                </div>
                <UBadge v-else :label="member.role" variant="subtle" color="neutral" class="capitalize" />
            </li>
        </ul>
    </section>
    <template v-if="canManage">
        <section class="settings-section" aria-labelledby="team-invite">
            <div class="settings-section-head"><h2 id="team-invite">Invite someone</h2></div>
            <UForm :state="invite" class="grid sm:grid-cols-[1fr_auto_auto] gap-3 items-end mt-4" @submit="sendInvite">
                <UFormField label="Email" name="email" :error="invite.errors.email" required>
                    <UInput v-model="invite.email" type="email" autocomplete="off" class="w-full" />
                </UFormField>
                <UFormField label="Role" name="role" :error="invite.errors.role">
                    <USelect v-model="invite.role" :items="roles" />
                </UFormField>
                <UButton type="submit" label="Send invitation" :loading="invite.processing" />
            </UForm>
            <p class="data-note mt-3">Viewers read the reports; owners also manage the site and its team. Invitations are e-mailed through the server set up under Administration, Mail (or in .env). Without one, you get a link to send yourself.</p>
        </section>
        <section v-if="invitations.length" class="settings-section" aria-labelledby="team-pending">
            <div class="settings-section-head">
                <h2 id="team-pending">Pending invitations</h2>
                <span class="tabular-nums">{{ invitations.length }}</span>
            </div>
            <ul class="settings-rows">
                <li v-for="invitation in invitations" :key="invitation.id">
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate">{{ invitation.email }}</p>
                        <p class="text-xs text-muted">
                            <span class="capitalize">{{ invitation.role }}</span>, expires {{ day(invitation.expires_at) }}<span v-if="invitation.sent">, e-mailed</span>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <UButton label="Resend" color="neutral" variant="outline" size="sm" @click="resend(invitation)" />
                        <UButton label="Revoke" color="neutral" variant="ghost" size="sm" @click="revoke(invitation)" />
                    </div>
                </li>
            </ul>
        </section>
    </template>
    <div class="settings-danger">
        <p>Leaving removes your access to {{ website.domain }}. An owner can invite you again.</p>
        <UButton label="Leave site" color="error" variant="outline" size="sm" @click="leave" />
    </div>
    </div>
</template>
