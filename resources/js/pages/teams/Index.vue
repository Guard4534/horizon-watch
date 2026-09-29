<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    PhArrowsLeftRight,
    PhPencilSimple,
    PhPlus,
    PhSignOut,
    PhTrashSimple,
    PhUsersThree,
} from '@phosphor-icons/vue';
import { ref } from 'vue';
import ConfirmByNameDialog from '@/components/ConfirmByNameDialog.vue';
import Heading from '@/components/Heading.vue';
import CreateOrganizationModal from '@/components/teams/CreateOrganizationModal.vue';
import LeaveOrganizationModal from '@/components/teams/LeaveOrganizationModal.vue';
import RenameOrganizationModal from '@/components/teams/RenameOrganizationModal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as membersIndex } from '@/routes/members';
import { destroy, switchMethod } from '@/routes/teams';

defineOptions({
    layout: { title: 'Organizations' },
});

type Organization = App.Data.Teams.UserTeamData;

defineProps<{
    teams: Organization[];
}>();

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

    <h1 class="sr-only">{{ $t('Organizations') }}</h1>

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                variant="small"
                :title="$t('Organizations')"
                :description="
                    $t(
                        'Every application, environment, member and alert rule belongs to one organization.',
                    )
                "
            />

            <CreateOrganizationModal>
                <Button data-test="teams-new-team-button">
                    <PhPlus /> {{ $t('New organization') }}
                </Button>
            </CreateOrganizationModal>
        </div>

        <div class="space-y-3">
            <div
                v-for="team in teams"
                :key="team.id"
                data-test="team-row"
                class="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ team.name }}</span>
                        <Badge v-if="team.isCurrent" variant="secondary">
                            {{ $t('Current') }}
                        </Badge>
                    </div>
                    <span class="text-muted-foreground text-sm">
                        {{ team.roleLabel }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="!team.isCurrent"
                        data-test="team-switch-button"
                        variant="secondary"
                        size="sm"
                        @click="switchTo(team)"
                    >
                        <PhArrowsLeftRight class="h-4 w-4" />
                        {{ $t('Switch') }}
                    </Button>

                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="membersIndex(team.slug)">
                            <PhUsersThree class="h-4 w-4" />
                            {{ $t('Members') }}
                        </Link>
                    </Button>

                    <Button
                        v-if="canManage(team)"
                        data-test="team-rename-button"
                        variant="ghost"
                        size="sm"
                        :aria-label="$t('Rename the organization')"
                        @click="renaming = team"
                    >
                        <PhPencilSimple class="h-4 w-4" />
                    </Button>

                    <Button
                        v-if="team.role !== 'owner'"
                        data-test="team-leave-button"
                        variant="ghost"
                        size="sm"
                        :aria-label="$t('Leave the organization')"
                        @click="leaving = team"
                    >
                        <PhSignOut class="h-4 w-4" />
                    </Button>

                    <Button
                        v-if="team.role === 'owner'"
                        data-test="team-delete-button"
                        variant="ghost"
                        size="sm"
                        :aria-label="$t('Delete the organization')"
                        @click="deleting = team"
                    >
                        <PhTrashSimple class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <p
                v-if="teams.length === 0"
                class="text-muted-foreground py-8 text-center"
            >
                {{ $t("You don't belong to any organization yet.") }}
            </p>
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
