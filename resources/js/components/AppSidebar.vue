<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    PhBellRinging,
    PhSlidersHorizontal,
    PhSquaresFour,
    PhStack,
    PhUsersThree,
} from '@phosphor-icons/vue';
import { computed } from 'vue';
import BrandMark from '@/components/nocturne/BrandMark.vue';
import NavUser from '@/components/NavUser.vue';
import SidebarLink from '@/components/SidebarLink.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import { useLocale } from '@/composables/useLocale';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { wall } from '@/routes';
import { index as alertsIndex } from '@/routes/alerts';
import { index as alertRulesIndex } from '@/routes/alert-rules';
import { index as applicationsIndex } from '@/routes/applications';
import { edit as editTeam } from '@/routes/teams';

const page = usePage();
const slug = useTeamSlug();
const { locale, setLocale } = useLocale();

const path = computed(() => page.url.split('?')[0]);
const startsWith = (...prefixes: string[]) =>
    prefixes.some((prefix) => path.value.startsWith(prefix));
</script>

<template>
    <aside
        class="sticky top-0 box-border flex h-screen w-[216px] flex-none flex-col"
        style="
            gap: var(--nc-space-6);
            padding: var(--nc-space-4) var(--nc-space-3);
            border-right: 1px solid var(--nc-divider);
        "
    >
        <div
            class="flex items-center gap-[9px]"
            style="padding: 0 var(--nc-space-2)"
        >
            <BrandMark />
            <div
                class="nc-seg ml-auto"
                style="border-radius: var(--nc-radius-sm)"
            >
                <label
                    v-for="option in ['it', 'en'] as const"
                    :key="option"
                    class="nc-seg-opt"
                    style="padding: 1px 5px; font-size: 10px"
                >
                    <input
                        type="radio"
                        name="sidebar-locale"
                        :checked="locale === option"
                        @change="setLocale(option)"
                    />
                    {{ option.toUpperCase() }}
                </label>
            </div>
        </div>

        <nav class="flex flex-col gap-[2px]">
            <div
                class="nc-label"
                style="padding: 0 var(--nc-space-2) var(--nc-space-2)"
            >
                {{ $t('Monitoring') }}
            </div>
            <SidebarLink
                :href="wall(slug)"
                :icon="PhSquaresFour"
                label="Status wall"
                :active="startsWith(`/${slug}/wall`)"
            />
            <SidebarLink
                :href="applicationsIndex(slug)"
                :icon="PhStack"
                label="Applications"
                :active="
                    startsWith(`/${slug}/applications`, `/${slug}/environments`)
                "
            />
            <SidebarLink
                :href="alertsIndex(slug)"
                :icon="PhBellRinging"
                label="Alerts"
                :active="startsWith(`/${slug}/alerts`)"
                :badge="page.props.openAlertCount"
            />
        </nav>

        <nav class="flex flex-col gap-[2px]">
            <div
                class="nc-label"
                style="padding: 0 var(--nc-space-2) var(--nc-space-2)"
            >
                {{ $t('Organization') }}
            </div>
            <SidebarLink
                :href="alertRulesIndex({ current_team: slug })"
                :icon="PhSlidersHorizontal"
                label="Alert settings"
                :active="startsWith(`/${slug}/alert-rules`)"
            />
            <SidebarLink
                :href="editTeam(slug)"
                :icon="PhUsersThree"
                label="Members"
                :active="startsWith('/settings/teams')"
            />
        </nav>

        <div class="mt-auto flex flex-col" style="gap: var(--nc-space-3)">
            <TeamSwitcher />
            <NavUser />
        </div>
    </aside>
</template>
