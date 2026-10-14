<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { PhCheck, PhCopy } from '@phosphor-icons/vue';
import { useClipboard } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLocale } from '@/composables/useLocale';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatRelative, roleTagClass } from '@/lib/members';
import {
    destroy as revokeInvitation,
    link as invitationLink,
    resend as resendInvitation,
} from '@/routes/members/invitations';

const { invitations, canCopyLinks } = defineProps<{
    invitations: App.Data.Teams.InvitationData[];
    canCopyLinks: boolean;
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

const http = useHttp();

const shown = ref<{
    invitation: App.Data.Teams.InvitationData;
    url: string | null;
} | null>(null);

const { copy, copied } = useClipboard({ copiedDuring: 2000, legacy: true });

function askLink(invitation: App.Data.Teams.InvitationData): void {
    shown.value = { invitation, url: null };
}

async function createLink(): Promise<void> {
    const target = shown.value;

    if (!target) {
        return;
    }

    busy.value = target.invitation.id;

    try {
        const { url } = (await http.submit(
            invitationLink([slug.value, target.invitation.id]),
        )) as { url: string };

        if (shown.value === target) {
            shown.value = { ...target, url };
        }
    } catch (error) {
        const status = (error as { response?: { status?: number } } | null)
            ?.response?.status;

        toast.error(
            status === 429
                ? trans('Too many attempts. Wait a minute and try again.')
                : trans(
                      'The invitation link could not be loaded. Reload the page and try again.',
                  ),
        );
    } finally {
        busy.value = null;
    }
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
                                v-if="canCopyLinks"
                                type="button"
                                class="nc-btn nc-btn-ghost nc-t-xs"
                                :disabled="busy === invitation.id"
                                @click="askLink(invitation)"
                            >
                                {{ $t('New link') }}
                            </button>
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

        <Dialog
            :open="shown !== null"
            @update:open="(open) => !open && (shown = null)"
        >
            <DialogContent v-if="shown">
                <DialogHeader>
                    <DialogTitle>{{ $t('Invitation link') }}</DialogTitle>
                    <DialogDescription v-if="shown.url === null">
                        {{
                            $t(
                                'A new link replaces every earlier link to this invitation, the one in the email included. Whoever opens it can join as :role with the address :email until the invitation expires.',
                                {
                                    role: shown.invitation.roleLabel,
                                    email: shown.invitation.email,
                                },
                            )
                        }}
                    </DialogDescription>
                    <DialogDescription v-else>
                        {{
                            $t(
                                'Whoever opens this link can join as :role with the address :email until the invitation expires. Send it to that person only. Earlier links to this invitation no longer work.',
                                {
                                    role: shown.invitation.roleLabel,
                                    email: shown.invitation.email,
                                },
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <div v-if="shown.url !== null" class="flex items-center gap-2">
                    <input
                        class="nc-input nc-code nc-t-sm"
                        :value="shown.url"
                        readonly
                        autocomplete="off"
                        spellcheck="false"
                        :aria-label="$t('Invitation link')"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <button
                        type="button"
                        class="nc-btn nc-btn-secondary nc-t-xs flex-none"
                        @click="copy(shown.url)"
                    >
                        <PhCheck v-if="copied" :size="14" />
                        <PhCopy v-else :size="14" />
                        {{ copied ? $t('Copied') : $t('Copy') }}
                    </button>
                </div>
                <DialogFooter v-if="shown.url === null">
                    <button
                        type="button"
                        class="nc-btn nc-btn-secondary"
                        @click="shown = null"
                    >
                        {{ $t('Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="nc-btn nc-btn-primary"
                        :disabled="busy === shown.invitation.id"
                        @click="createLink"
                    >
                        {{ $t('Create new link') }}
                    </button>
                </DialogFooter>
                <DialogFooter v-else>
                    <button
                        type="button"
                        class="nc-btn nc-btn-secondary"
                        @click="shown = null"
                    >
                        {{ $t('Close') }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </SectionCard>
</template>
