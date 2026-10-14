<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    PhBellRinging,
    PhSquaresFour,
    PhStack,
    PhUser,
} from '@phosphor-icons/vue';
import { computed } from 'vue';
import { useCurrentPath } from '@/composables/useCurrentPath';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { me, wall } from '@/routes';
import { index as alertsIndex } from '@/routes/alerts';
import { index as applicationsIndex } from '@/routes/applications';
import { edit as editProfile } from '@/routes/profile';

const page = usePage();
const slug = useTeamSlug();
const { startsWith } = useCurrentPath();

const openAlerts = computed(() => page.props.openAlertCount ?? 0);
const badge = computed(() =>
    openAlerts.value > 99 ? '99+' : String(openAlerts.value),
);

const active = computed(() => ({
    wall: startsWith(`/${slug.value}/wall`),
    alerts: startsWith(`/${slug.value}/alerts`),
    apps: startsWith(
        `/${slug.value}/applications`,
        `/${slug.value}/environments`,
    ),
    me: slug.value
        ? startsWith(`/${slug.value}/me`, '/settings')
        : startsWith('/settings'),
}));

const current = (on: boolean) => (on ? ('page' as const) : undefined);
</script>

<template>
    <nav class="tab-bar" :aria-label="$t('Main navigation')">
        <template v-if="slug">
            <Link
                :href="wall(slug)"
                class="tab"
                :class="{ 'is-active': active.wall }"
                :aria-current="current(active.wall)"
            >
                <PhSquaresFour :size="18" />
                <span class="tab-label">{{ $t('Wall') }}</span>
            </Link>
            <Link
                :href="alertsIndex(slug)"
                class="tab"
                :class="{ 'is-active': active.alerts }"
                :aria-current="current(active.alerts)"
                :aria-label="
                    openAlerts
                        ? $t('Alerts, :count open', {
                              count: String(openAlerts),
                          })
                        : undefined
                "
            >
                <span class="tab-icon">
                    <PhBellRinging :size="18" />
                    <b
                        v-if="openAlerts"
                        class="badge nc-num"
                        aria-hidden="true"
                        >{{ badge }}</b
                    >
                </span>
                <span class="tab-label">{{ $t('Alerts') }}</span>
            </Link>
            <Link
                :href="applicationsIndex(slug)"
                class="tab"
                :class="{ 'is-active': active.apps }"
                :aria-current="current(active.apps)"
            >
                <PhStack :size="18" />
                <span class="tab-label">{{ $t('Apps') }}</span>
            </Link>
        </template>
        <Link
            :href="slug ? me(slug) : editProfile()"
            class="tab"
            :class="{ 'is-active': active.me }"
            :aria-current="current(active.me)"
        >
            <PhUser :size="18" />
            <span class="tab-label">{{ $t('Profile') }}</span>
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

.tab-label {
    font-size: 9px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.tab-icon {
    position: relative;
    display: inline-flex;
}

.badge {
    position: absolute;
    top: -5px;
    left: 11px;
    min-width: 16px;
    padding: 1px 4px;
    border-radius: 99px;
    background: var(--nc-accent-800);
    color: var(--nc-accent-100);
    font-size: 9px;
    font-weight: 600;
    line-height: 12px;
    text-align: center;
}

.tab.is-active {
    color: var(--nc-accent);
    box-shadow: inset 0 2px 0 var(--nc-accent);
}
</style>
