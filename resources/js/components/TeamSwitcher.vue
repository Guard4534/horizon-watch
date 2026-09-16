<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { PhCheck, PhCaretUpDown, PhPlus } from '@phosphor-icons/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { switchMethod } from '@/routes/teams';
import type { Team } from '@/types';

const page = usePage();
const isMobile = ref(false);
let mediaQuery: MediaQueryList | null = null;
const updateIsMobile = () => {
    if (mediaQuery) {
        isMobile.value = mediaQuery.matches;
    }
};

const currentTeam = computed(() => page.props.currentTeam);
const teams = computed(() => page.props.teams ?? []);

const switchTeam = (team: Team) => {
    const previousTeamSlug = currentTeam.value?.slug;

    router.visit(switchMethod(team.slug), {
        onFinish: () => {
            if (!previousTeamSlug || typeof window === 'undefined') {
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

onMounted(() => {
    mediaQuery = window.matchMedia('(max-width: 767px)');
    updateIsMobile();
    mediaQuery.addEventListener('change', updateIsMobile);
});

onUnmounted(() => {
    mediaQuery?.removeEventListener('change', updateIsMobile);
});
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                data-test="team-switcher-trigger"
                class="org-card block w-full text-left"
            >
                <span class="nc-label block">{{ $t('Organization') }}</span>
                <span
                    class="mt-[3px] flex items-center gap-[6px]"
                    style="font-size: 13px"
                >
                    {{ currentTeam?.name ?? $t('Select organization') }}
                    <PhCaretUpDown
                        :size="13"
                        class="ml-auto"
                        style="color: var(--nc-neutral-500)"
                    />
                </span>
                <span
                    class="mt-[2px] block"
                    style="font-size: 11px; color: var(--nc-neutral-500)"
                    >{{ currentTeam?.roleLabel }}</span
                >
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
                <PhCheck
                    v-if="currentTeam?.id === team.id"
                    class="ml-auto h-4 w-4"
                />
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <CreateTeamModal>
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
            </CreateTeamModal>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

<style scoped>
.org-card {
    padding: var(--nc-space-3);
    border: 0;
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    box-shadow: var(--nc-shadow-sm);
    color: inherit;
    font: inherit;
    cursor: pointer;
}

.org-card:hover {
    box-shadow: var(--nc-shadow-md);
}
</style>
