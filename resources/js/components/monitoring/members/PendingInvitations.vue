<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatRelative, roleTagClass } from '@/lib/members';
import {
    destroy as revokeInvitation,
    resend as resendInvitation,
} from '@/routes/members/invitations';

const { invitations } = defineProps<{
    invitations: App.Data.Teams.InvitationData[];
}>();

const slug = useTeamSlug();

// Neither action has a payload; useForm is here so the whole phase submits
// the same way, and its submit() takes the route object's own method (POST
// to resend, DELETE to revoke).
const form = useForm({});

// The id of the invitation a request is in flight for, so only its own two
// buttons go quiet. form.processing cannot say which row that is, and there
// is one form for every row.
const busy = ref<number | null>(null);

function act(
    invitation: App.Data.Teams.InvitationData,
    route: typeof resendInvitation | typeof revokeInvitation,
) {
    // The id, never the code: the join code is the invitee's credential and
    // would end up in the web server's access log (see routes/settings.php).
    //
    // Resending is rate limited to six a minute; that 429 and the 409 on an
    // invitation somebody has just accepted are handled by the exception
    // handler, not here.
    busy.value = invitation.id;

    form.submit(route([slug.value, invitation.id]), {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
}
</script>

<template>
    <SectionCard :title="$t('Pending invitations')">
        <div
            v-if="!invitations.length"
            style="font-size: 12px; color: var(--nc-neutral-500)"
        >
            {{ $t('No invitation is waiting to be accepted.') }}
        </div>

        <div v-else class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Email') }}</th>
                        <th>{{ $t('Role') }}</th>
                        <th>{{ $t('Visible environments') }}</th>
                        <th>{{ $t('Sent') }}</th>
                        <th>{{ $t('Expires') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="invitation in invitations" :key="invitation.id">
                        <td style="font-size: 13px">{{ invitation.email }}</td>
                        <td>
                            <span :class="roleTagClass(invitation.role)">{{
                                invitation.roleLabel
                            }}</span>
                        </td>
                        <td
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-400);
                            "
                        >
                            {{ invitation.visibilityLabel }}
                        </td>
                        <td
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-500);
                            "
                        >
                            {{ formatRelative(invitation.invitedAt) }}
                        </td>
                        <td
                            style="
                                font-size: 12px;
                                color: var(--nc-neutral-500);
                            "
                        >
                            {{ formatRelative(invitation.expiresAt) }}
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <button
                                type="button"
                                class="nc-btn nc-btn-ghost"
                                style="font-size: 12px"
                                :disabled="busy === invitation.id"
                                @click="act(invitation, resendInvitation)"
                            >
                                {{ $t('Resend') }}
                            </button>
                            <button
                                type="button"
                                class="nc-btn nc-btn-ghost"
                                style="
                                    font-size: 12px;
                                    color: var(--nc-neutral-400);
                                "
                                :disabled="busy === invitation.id"
                                @click="act(invitation, revokeInvitation)"
                            >
                                {{ $t('Revoke') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
