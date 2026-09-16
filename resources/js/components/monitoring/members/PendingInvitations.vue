<script setup lang="ts">
import { router } from '@inertiajs/vue3';
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

// The id of the invitation a request is in flight for, so only its own two
// buttons go quiet.
const busy = ref<number | null>(null);

function act(
    invitation: App.Data.Teams.InvitationData,
    route: typeof resendInvitation | typeof revokeInvitation,
) {
    // The id, never the code: the join code is the invitee's credential and
    // would end up in the web server's access log (see routes/settings.php).
    //
    // Resending is rate limited to six a minute. A 429 is not an Inertia
    // response, so it surfaces as Inertia's own "unexpected response"
    // modal rather than as a toast; turning it into one means an
    // Inertia-aware handler in the exception layer, which is shared ground
    // and not this component's to claim.
    router.visit(route([slug.value, invitation.id]), {
        preserveScroll: true,
        onStart: () => (busy.value = invitation.id),
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
