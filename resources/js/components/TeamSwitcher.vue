<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { PhCheck, PhCaretUpDown, PhPlus } from '@phosphor-icons/vue';
import { computed } from 'vue';
import CreateOrganizationModal from '@/components/teams/CreateOrganizationModal.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useIsMobile } from '@/composables/useIsMobile';
import { switchMethod } from '@/routes/teams';

const page = usePage();
const isMobile = useIsMobile();

const currentTeam = computed(() => page.props.currentTeam);
const teams = computed(() => page.props.teams ?? []);

const switchTeam = (team: App.Data.Teams.UserTeamData) => {
    const previousTeamSlug = currentTeam.value?.slug;

    router.visit(switchMethod(team.slug), {
        onFinish: () => {
            if (!previousTeamSlug) {
                router.reload();

                return;
            }

            const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
            const segment = `/${previousTeamSlug}`;

            if (currentUrl.includes(segment)) {
                router.visit(currentUrl.replace(segment, `/${team.slug}`), {
                    replace: true,
                });

                return;
            }

            router.reload();
        },
    });
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                data-test="team-switcher-trigger"
                class="nc-card org-card block w-full text-left"
            >
                <span class="nc-label block">{{ $t('Organization') }}</span>
                <span class="nc-t-sm mt-[3px] flex items-center gap-[6px]">
                    {{ currentTeam?.name ?? $t('Select organization') }}
                    <PhCaretUpDown :size="13" class="nc-tone-muted ml-auto" />
                </span>
                <span class="nc-t-2xs nc-tone-muted mt-[2px] block">{{
                    currentTeam?.roleLabel
                }}</span>
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
            :side="isMobile ? 'bottom' : 'right'"
            align="start"
            :side-offset="4"
        >
            <DropdownMenuLabel class="text-muted-foreground text-xs">
                {{ $t('Organizations') }}
            </DropdownMenuLabel>
            <DropdownMenuItem
                v-for="team in teams"
                :key="team.id"
                data-test="team-switcher-item"
                class="cursor-pointer gap-2 p-2"
                @click="switchTeam(team)"
            >
                {{ team.name }}
                <PhCheck v-if="team.isCurrent" class="ml-auto h-4 w-4" />
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <CreateOrganizationModal>
                <DropdownMenuItem
                    data-test="team-switcher-new-team"
                    class="cursor-pointer gap-2 p-2"
                    @select.prevent
                >
                    <PhPlus class="h-4 w-4" />
                    <span class="text-muted-foreground">{{
                        $t('New organization')
                    }}</span>
                </DropdownMenuItem>
            </CreateOrganizationModal>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

<style scoped>
.org-card {
    padding: var(--nc-space-3);
    border: 0;
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.org-card:hover {
    box-shadow: var(--nc-shadow-md);
}
</style>
