<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    PhBellRinging,
    PhSquaresFour,
    PhStack,
    PhUser,
} from '@phosphor-icons/vue';
import { computed } from 'vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { me, wall } from '@/routes';
import { index as alertsIndex } from '@/routes/alerts';
import { index as applicationsIndex } from '@/routes/applications';

const page = usePage();
const slug = useTeamSlug();

const path = computed(() => page.url.split('?')[0]);
const startsWith = (...prefixes: string[]) =>
    prefixes.some((prefix) => path.value.startsWith(prefix));
</script>

<template>
    <nav class="tab-bar" :aria-label="$t('Main navigation')">
        <Link
            :href="wall(slug)"
            class="tab"
            :class="{ 'is-active': startsWith(`/${slug}/wall`) }"
        >
            <PhSquaresFour :size="18" />
            <span>{{ $t('Wall') }}</span>
        </Link>
        <Link
            :href="alertsIndex(slug)"
            class="tab"
            :class="{ 'is-active': startsWith(`/${slug}/alerts`) }"
        >
            <PhBellRinging :size="18" />
            <span>{{ $t('Alerts') }}</span>
        </Link>
        <Link
            :href="applicationsIndex(slug)"
            class="tab"
            :class="{
                'is-active': startsWith(
                    `/${slug}/applications`,
                    `/${slug}/environments`,
                ),
            }"
        >
            <PhStack :size="18" />
            <span>{{ $t('Apps') }}</span>
        </Link>
        <Link
            :href="me(slug)"
            class="tab"
            :class="{
                'is-active': startsWith(`/${slug}/me`, '/settings'),
            }"
        >
            <PhUser :size="18" />
            <span>{{ $t('Profile') }}</span>
        </Link>
    </nav>
</template>

<style scoped>
.tab-bar {
    position: fixed;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 40;
    display: flex;
    padding-bottom: env(safe-area-inset-bottom);
    background: var(--nc-bg);
    border-top: 1px solid var(--nc-divider);
}

.tab {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    padding: var(--nc-space-3) 0;
    color: var(--nc-neutral-600);
    text-decoration: none;
}

.tab span {
    font-size: 9px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.tab.is-active {
    color: var(--nc-accent);
    box-shadow: inset 0 2px 0 var(--nc-accent);
}
</style>
