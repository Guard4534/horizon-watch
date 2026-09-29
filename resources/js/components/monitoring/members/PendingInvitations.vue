<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useLocale } from '@/composables/useLocale';
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
const { locale } = useLocale();

const form = useForm({});

const busy = ref<number | null>(null);

function act(
    invitation: App.Data.Teams.InvitationData,
    route: typeof resendInvitation | typeof revokeInvitation,
) {
    busy.value = invitation.id;

    form.submit(route([slug.value, invitation.id]), {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
}
</script>

<template>
    <SectionCard :title="$t('Pending invitations')">
        <div v-if="!invitations.length" class="nc-t-xs nc-tone-muted">
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
                        <td class="nc-t-sm">{{ invitation.email }}</td>
                        <td>
                            <span :class="roleTagClass(invitation.role)">{{
                                invitation.roleLabel
                            }}</span>
                        </td>
                        <td class="nc-t-xs nc-tone-soft">
                            {{ invitation.visibilityLabel }}
                        </td>
                        <td class="nc-t-xs nc-tone-muted">
                            {{ formatRelative(invitation.invitedAt, locale) }}
                        </td>
                        <td class="nc-t-xs nc-tone-muted">
                            {{ formatRelative(invitation.expiresAt, locale) }}
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <button
                                type="button"
                                class="nc-btn nc-btn-ghost nc-t-xs"
                                :disabled="busy === invitation.id"
                                @click="act(invitation, resendInvitation)"
                            >
                                {{ $t('Resend') }}
                            </button>
                            <button
                                type="button"
                                class="nc-btn nc-btn-ghost nc-t-xs nc-tone-soft"
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
