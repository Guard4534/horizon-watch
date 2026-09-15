<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhPauseCircle, PhPlugs, PhWarning, PhWarningOctagon } from '@phosphor-icons/vue';
import EnvSwatch from '@/components/nocturne/EnvSwatch.vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { formatMinutesAgo, statusColor } from '@/lib/monitoring';
import { index as alertsIndex } from '@/routes/alerts';
import { show as showEnvironment } from '@/routes/environments';

defineProps<{
    anomalies: App.Data.Monitoring.AlertData[];
}>();

const slug = useTeamSlug();

const ICONS = { inactive: PhWarningOctagon, unreachable: PhPlugs, paused: PhPauseCircle, degraded: PhWarning, active: PhWarning };

const TITLES: Record<App.Enums.EnvironmentStatus, string> = {
    inactive: 'Master supervisor inactive, queues not draining',
    unreachable: 'Horizon endpoint unreachable (timeout)',
    paused: 'Supervisor paused for more than 2 hours',
    degraded: 'Max wait above threshold on several queues',
    active: 'Max wait above threshold on several queues',
};
</script>

<template>
    <SectionCard :title="$t('Open anomalies')">
        <template #actions>
            <Link :href="alertsIndex(slug)" style="font-size: 11px">{{ $t('Full log') }}</Link>
        </template>
        <div class="flex flex-col" style="gap: var(--nc-space-3)">
            <Link
                v-for="alert in anomalies"
                :key="alert.id"
                :href="showEnvironment({ current_team: slug, environment: alert.environmentId })"
                class="anomaly block"
            >
                <span class="flex items-start gap-[9px]">
                    <component :is="ICONS[alert.environmentStatus]" :size="15" class="mt-[2px]" :style="{ color: statusColor(alert.environmentStatus) }" />
                    <span class="min-w-0 flex-1">
                        <span class="block" style="font-size: 13px; line-height: 1.35">{{ $t(TITLES[alert.environmentStatus]) }}</span>
                        <span class="mt-[3px] flex items-center gap-[6px]" style="font-size: 11px; color: var(--nc-neutral-500)">
                            <EnvSwatch :color="alert.color" shape="bar" :size="11" />
                            {{ alert.applicationName }} / {{ alert.environmentName }} · {{ formatMinutesAgo(alert.minutesAgo) }}
                        </span>
                        <span class="mt-[3px] block" style="font-size: 11px; color: var(--nc-neutral-600)">{{ $t('Email to 3 recipients · webhook ok') }}</span>
                    </span>
                </span>
            </Link>
        </div>
    </SectionCard>
</template>

<style scoped>
.anomaly {
    color: inherit;
    text-decoration: none;
    padding: 0 0 var(--nc-space-3);
    border-bottom: 1px solid color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.anomaly:hover {
    opacity: 0.72;
}
</style>
