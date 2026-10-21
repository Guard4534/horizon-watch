<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    PhArrowsLeftRight,
    PhPencilSimple,
    PhPlus,
    PhSignOut,
    PhTrashSimple,
    PhUsersThree,
} from '@phosphor-icons/vue';
import { computed, ref } from 'vue';
import ConfirmByNameDialog from '@/components/ConfirmByNameDialog.vue';
import CreateOrganizationModal from '@/components/teams/CreateOrganizationModal.vue';
import LeaveOrganizationModal from '@/components/teams/LeaveOrganizationModal.vue';
import RenameOrganizationModal from '@/components/teams/RenameOrganizationModal.vue';
import { index as membersIndex } from '@/routes/members';
import { destroy, switchMethod } from '@/routes/teams';

defineOptions({
    layout: { title: 'Organizations' },
});

type Organization = App.Data.Teams.UserTeamData;

defineProps<{
    teams: Organization[];
}>();

const page = usePage();
const canCreateOrganizations = computed(
    () => page.props.canCreateOrganizations,
);

const renaming = ref<Organization | null>(null);
const leaving = ref<Organization | null>(null);
const deleting = ref<Organization | null>(null);

const canManage = (team: Organization) =>
    team.role === 'owner' || team.role === 'admin';

function switchTo(team: Organization): void {
    router.visit(switchMethod(team.slug));
}
</script>

<template>
    <Head :title="$t('Organizations')" />

    <div class="flex flex-col space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="nc-t-sm nc-tone-muted">
                {{
                    $t(
                        'Every application, environment, member and alert rule belongs to one organization.',
                    )
                }}
            </p>

            <CreateOrganizationModal v-if="canCreateOrganizations">
                <button
                    type="button"
                    class="nc-btn nc-btn-primary"
                    data-test="teams-new-team-button"
                >
                    <PhPlus :size="16" /> {{ $t('New organization') }}
                </button>
            </CreateOrganizationModal>
        </div>

        <div class="space-y-3">
            <div
                v-for="team in teams"
                :key="team.id"
                data-test="team-row"
                class="nc-card flex flex-wrap items-center justify-between gap-4"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span>{{ team.name }}</span>
                        <span
                            v-if="team.isCurrent"
                            class="nc-tag nc-tag-sm nc-tag-neutral"
                        >
                            {{ $t('Current') }}
                        </span>
                    </div>
                    <span class="nc-t-sm nc-tone-muted">
                        {{ team.roleLabel }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="!team.isCurrent"
                        type="button"
                        class="nc-btn nc-btn-secondary"
                        data-test="team-switch-button"
                        @click="switchTo(team)"
                    >
                        <PhArrowsLeftRight :size="16" />
                        {{ $t('Switch') }}
                    </button>

                    <Link
                        :href="membersIndex(team.slug)"
                        class="nc-btn nc-btn-ghost"
                    >
                        <PhUsersThree :size="16" />
                        {{ $t('Members') }}
                    </Link>

                    <button
                        v-if="canManage(team)"
                        type="button"
                        class="nc-btn nc-btn-ghost"
                        data-test="team-rename-button"
                        :aria-label="$t('Rename the organization')"
                        @click="renaming = team"
                    >
                        <PhPencilSimple :size="16" />
                    </button>

                    <button
                        v-if="team.role !== 'owner'"
                        type="button"
                        class="nc-btn nc-btn-ghost nc-tone-down"
                        data-test="team-leave-button"
                        :title="$t('Leave the organization')"
                        :aria-label="$t('Leave the organization')"
                        @click="leaving = team"
                    >
                        <PhSignOut :size="16" />
                    </button>

                    <button
                        v-if="team.role === 'owner'"
                        type="button"
                        class="nc-btn nc-btn-ghost nc-tone-down"
                        data-test="team-delete-button"
                        :title="$t('Delete the organization')"
                        :aria-label="$t('Delete the organization')"
                        @click="deleting = team"
                    >
                        <PhTrashSimple :size="16" />
                    </button>
                </div>
            </div>

            <div
                v-if="teams.length === 0"
                data-test="teams-empty"
                class="nc-tone-muted space-y-2 py-8 text-center"
            >
                <p>{{ $t("You don't belong to any organization yet.") }}</p>
                <p class="nc-t-sm">
                    {{
                        canCreateOrganizations
                            ? $t(
                                  'Create one to start monitoring, or ask an admin of an existing organization to invite you.',
                              )
                            : $t(
                                  'Ask an admin of an organization to invite you: the link in the invitation email brings you in.',
                              )
                    }}
                </p>
            </div>
        </div>
    </div>

    <RenameOrganizationModal v-model:organization="renaming" />
    <LeaveOrganizationModal v-model:organization="leaving" />

    <ConfirmByNameDialog
        v-if="deleting"
        :open="true"
        :resource-name="deleting.name"
        :title="$t('Delete the organization')"
        :body="
            $t(
                'Everything below disappears as soon as you confirm, for everyone in the organization.',
            )
        "
        :items="[
            $t('Every application and environment of this organization.'),
            $t('Its alert rules, alerts and notification settings.'),
            $t('Every membership and pending invitation.'),
        ]"
        :confirm-label="$t('Delete the organization')"
        :url="destroy(deleting.slug).url"
        @update:open="(open: boolean) => !open && (deleting = null)"
    />
</template>
