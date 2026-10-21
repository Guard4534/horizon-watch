<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import InviteForm from '@/components/monitoring/members/InviteForm.vue';
import MemberTable from '@/components/monitoring/members/MemberTable.vue';
import PendingInvitations from '@/components/monitoring/members/PendingInvitations.vue';
import PermissionMatrix from '@/components/monitoring/members/PermissionMatrix.vue';
import WarningNotice from '@/components/monitoring/WarningNotice.vue';

defineOptions({
    layout: { title: 'Members' },
});

const { page } = defineProps<{
    page: App.Data.Pages.MembersPageData;
}>();

const shared = usePage();
</script>

<template>
    <Head :title="$t('Members')" />

    <div class="members-grid">
        <div class="flex min-w-0 flex-col" style="gap: var(--nc-space-4)">
            <WarningNotice
                v-if="
                    page.permissions.canInvite &&
                    shared.props.mailConfigured === false
                "
                :title="$t('Email is not set up')"
            >
                {{
                    $t(
                        'Invitations are not emailed until whoever runs this panel sets MAIL_HOST. Copy the link of a pending invitation and send it yourself.',
                    )
                }}
            </WarningNotice>

            <MemberTable
                :members="page.members"
                :environments="page.environments"
                :roles="page.assignableRoles"
                :visibilities="page.visibilities"
                :can-manage="page.permissions.canManageMembers"
                :can-transfer-ownership="page.permissions.canTransferOwnership"
            />

            <PendingInvitations
                v-if="page.permissions.canInvite"
                :invitations="page.invitations"
                :can-copy-links="page.permissions.canCopyInvitationLinks"
            />

            <PermissionMatrix :rows="page.matrix" :roles="page.roles" />
        </div>

        <div class="flex flex-col" style="gap: var(--nc-space-4)">
            <InviteForm
                v-if="page.permissions.canInvite"
                :environments="page.environments"
                :expires-days="page.invitationExpiresDays"
                :roles="page.assignableRoles"
                :visibilities="page.visibilities"
            />
        </div>
    </div>
</template>

<style scoped>
.members-grid {
    display: grid;
    align-items: start;
    padding: var(--nc-space-6);
    gap: var(--nc-space-6);
    grid-template-columns: minmax(0, 1fr) 270px;
}

@media (max-width: 1023px) {
    .members-grid {
        grid-template-columns: minmax(0, 1fr);
        padding: var(--nc-space-4);
        gap: var(--nc-space-4);
    }
}
</style>
