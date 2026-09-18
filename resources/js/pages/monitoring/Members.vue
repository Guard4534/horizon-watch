<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import InviteForm from '@/components/monitoring/members/InviteForm.vue';
import MemberTable from '@/components/monitoring/members/MemberTable.vue';
import PendingInvitations from '@/components/monitoring/members/PendingInvitations.vue';
import PermissionMatrix from '@/components/monitoring/members/PermissionMatrix.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';

defineOptions({
    layout: { title: 'Members' },
});

const { page } = defineProps<{
    page: App.Data.Pages.MembersPageData;
}>();
</script>

<template>
    <Head :title="$t('Members')" />

    <div
        class="grid items-start"
        style="
            padding: var(--nc-space-6);
            gap: var(--nc-space-6);
            grid-template-columns: minmax(0, 1fr) 270px;
        "
    >
        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <MemberTable
                :members="page.members"
                :environments="page.environments"
                :can-manage="page.permissions.canManageMembers"
            />

            <PendingInvitations
                v-if="page.permissions.canInvite"
                :invitations="page.invitations"
            />

            <PermissionMatrix :rows="page.matrix" />
        </div>

        <div class="flex flex-col" style="gap: var(--nc-space-4)">
            <InviteForm
                v-if="page.permissions.canInvite"
                :environments="page.environments"
                :expires-days="page.invitationExpiresDays"
            />

            <SectionCard :title="$t('How access works')">
                <div style="font-size: 12px; color: var(--nc-neutral-400)">
                    {{
                        $t(
                            'A user can belong to several organizations, with a separate role in each. Admins manage applications, environments, credentials and thresholds; members act on alerts; viewers only look. Visibility can be narrowed to a subset of environments.',
                        )
                    }}
                </div>
            </SectionCard>
        </div>
    </div>
</template>
